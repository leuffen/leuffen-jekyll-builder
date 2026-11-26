# frozen_string_literal: true

require "socket"
require "json"
require "timeout"
require "jekyll"
require_relative "jekyll-error-analyzer"

SOCKET_PATH = "/run/jekyll-builder/build.sock"
BUILD_TIMEOUT = 120
TERM_GRACE = 5
MAX_WORKERS = 16

def monotonic
  Process.clock_gettime(Process::CLOCK_MONOTONIC)
end

# Install instrumentation only in the disposable build child.
def instrument(timings, path)
  measure = lambda do |name, &operation|
    started = monotonic
    begin
      operation.call
    ensure
      timings[name] = ((monotonic - started) * 1000).to_i
      File.write(path, JSON.generate(timings))
    end
  end

  Jekyll.singleton_class.prepend(Module.new do
    define_method(:configuration) do |*args, **kwargs, &block|
      measure.call("jekyllConfig") { super(*args, **kwargs, &block) }
    end
  end)

  Jekyll::Site.prepend(Module.new do
    {
      initialize: "jekyllSetup", read: "jekyllRead",
      generate: "jekyllGenerate", render: "jekyllRender",
      cleanup: "jekyllCleanup", write: "jekyllWrite"
    }.each do |method, name|
      define_method(method) do |*args, **kwargs, &block|
        measure.call(name) { super(*args, **kwargs, &block) }
      end
    end
    private :initialize
  end)
end

def build(root, started)
  Dir.chdir(root)
  ENV["HOME"] = root
  STDIN.reopen("/dev/null", "r")
  STDOUT.reopen(File.join(root, "stdout.log"), "w")
  STDERR.reopen(File.join(root, "stderr.log"), "w")
  STDOUT.sync = STDERR.sync = true

  timings = { "jekyllWorkerStartup" => ((monotonic - started) * 1000).to_i }
  instrument(timings, File.join(root, "timing.json"))
  JekyllForkErrorAnalyzer.install

  # Use Jekyll's normal build command, including plugins and error handling.
  Jekyll::Commands::Build.process(
    "source" => File.join(root, "source"),
    "destination" => File.join(root, "site"),
    "watch" => false,
    "serving" => false
  )
  0
rescue SystemExit => error
  error.status
rescue StandardError, ScriptError => error
  warn error.full_message(highlight: false)
  begin
    diagnostic = JekyllForkErrorAnalyzer.new(File.join(root, "source")).analyze(error)
    File.write(File.join(root, "diagnostic.json"), JSON.generate(diagnostic))
  rescue StandardError => diagnostic_error
    # Diagnostics must never replace the original build failure.
    warn "Could not extract structured diagnostic: #{diagnostic_error.message}"
  end
  1
end

def signal_group(signal, pid)
  Process.kill(signal, -pid)
rescue Errno::ESRCH
  nil
end

# A request supervisor owns the child and enforces the previous TERM/KILL deadline.
# The preloader itself never evaluates a project's configuration or plugins.
def supervise(socket)
  root = Timeout.timeout(5) { socket.gets(256) }&.chomp
  unless root && root.match?(%r{\A/tmp/jekyll-builder-[0-9a-f]{32}\z}) &&
      File.directory?(File.join(root, "source")) && File.directory?(File.join(root, "site"))
    socket.puts JSON.generate("error" => "Invalid build workspace")
    return
  end

  started = monotonic
  pid = fork do
    socket.close
    Process.setpgid(0, 0)
    exit! build(root, started)
  end
  begin
    Process.setpgid(pid, pid)
  rescue Errno::ESRCH
    # A very short build may already have exited.
  end

  deadline = started + BUILD_TIMEOUT
  timed_out = false
  status = nil
  loop do
    result = Process.waitpid2(pid, Process::WNOHANG)
    if result
      status = result.last
      break
    end

    now = monotonic
    if !timed_out && now >= deadline
      timed_out = true
      signal_group("TERM", pid)
    elsif timed_out && now >= deadline + TERM_GRACE
      signal_group("KILL", pid)
      _, status = Process.waitpid2(pid)
      break
    end
    sleep 0.01
  end

  # Do not leave subprocesses started by plugins running after the build.
  signal_group("KILL", pid)
  pid = nil
  code = timed_out ? 124 : (status.exitstatus || 128 + status.termsig)
  socket.puts JSON.generate("exitCode" => code)
rescue StandardError => error
  socket.puts JSON.generate("error" => error.message) rescue nil
ensure
  if pid
    signal_group("KILL", pid)
    Process.waitpid(pid) rescue nil
  end
  socket.close
end

# Only the Apache/PHP service account can connect to this local socket.
File.umask(0o077)
server = UNIXServer.new(SOCKET_PATH)
workers = []
stopping = false
Signal.trap("TERM") { stopping = true }
Signal.trap("INT") { stopping = true }

begin
  until stopping
    workers.reject! { |pid| Process.waitpid(pid, Process::WNOHANG) }
    next unless IO.select([server], nil, nil, 0.1)

    socket = server.accept
    if workers.length >= MAX_WORKERS
      socket.puts JSON.generate("error" => "Jekyll builder is busy") rescue nil
      socket.close
      next
    end

    workers << fork do
      server.close
      Signal.trap("TERM", "DEFAULT")
      Signal.trap("INT", "DEFAULT")
      supervise(socket)
      exit! 0
    end
    socket.close
  end
ensure
  server.close
  File.unlink(SOCKET_PATH) if File.socket?(SOCKET_PATH)
end
