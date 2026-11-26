# frozen_string_literal: true

# Extract native error information while the original Ruby exception is alive.
# Only project-relative paths are returned, never gem or container paths.
class JekyllForkErrorAnalyzer
  def initialize(source)
    @source = File.realpath(source)
  end

  def analyze(error)
    context = error.instance_variable_get(:@builder_error_context) || {}
    file = nil
    line = nil
    column = nil

    case error
    when Liquid::Error
      template = error.template_name
      file = project_file(template || context[:path])
      if file && error.line_number.to_i.positive?
        # Includes are parsed as files; documents/layouts can have stripped
        # front matter. Translate the template line only when the offset is known.
        if template && project_file(template) != project_file(context[:path])
          line = error.line_number.to_i
        elsif context[:content]
          line = liquid_source_line(file, context[:content], error.line_number.to_i)
        end
      end
    when Psych::SyntaxError
      file = project_file(error.file) || project_file(context[:path])
      if file
        line = error.line.to_i + (context[:front_matter] ? 1 : 0)
        column = error.column.to_i
      end
    else
      # Sass converters expose different location APIs depending on the version.
      if error.respond_to?(:filename)
        file = project_file(error.filename)
        if file
          line = error.line.to_i if error.respond_to?(:line)
          column = error.column.to_i if error.respond_to?(:column)
        end
      end

      unless file
        # A syntax error raised by require may name the plugin only in its message.
        if error.is_a?(SyntaxError)
          error.message.each_line do |message|
            match = message.match(/\A(.+?):(\d+):/)
            next unless match && (file = project_file(match[1]))
            line = match[2].to_i
            break
          end
        end
      end

      unless file
        Array(error.backtrace_locations).each do |frame|
          file = project_file(frame.absolute_path || frame.path)
          next unless file
          line = frame.lineno
          break
        end
      end
      file ||= project_file(context[:path])
    end

    {
      "file" => file,
      "line" => line && line.positive? ? line : nil,
      "column" => line && line.positive? && column && column.positive? ? column : nil,
      "errorType" => error.class.name,
      "cause" => error.message.to_s.gsub(@source + "/", "").encode("UTF-8", invalid: :replace, undef: :replace)[0, 8192]
    }
  end

  private

  def project_file(path)
    return nil unless path.is_a?(String) && !path.empty?
    # Jekyll supplies both absolute paths and paths relative to the source.
    resolved = File.realpath(File.expand_path(path, @source))
    return nil unless resolved.start_with?(@source + "/") && File.file?(resolved)
    resolved.delete_prefix(@source + "/")
  rescue SystemCallError, ArgumentError
    nil
  end

  def liquid_source_line(file, content, line)
    path = File.join(@source, file)
    return nil if File.size(path) > 1024 * 1024
    raw = File.binread(path)
    template = content.b
    return line if raw == template

    # Only a verified leading front-matter block is a valid line offset.
    match = raw.match(/\A(?:\xEF\xBB\xBF)?---[ \t]*\r?\n.*?\r?\n---[ \t]*\r?\n/mn)
    # Empty front matter has adjacent delimiters.
    match ||= raw.match(/\A(?:\xEF\xBB\xBF)?---[ \t]*\r?\n---[ \t]*\r?\n/n)
    return nil unless match && raw.byteslice(match.end(0)..) == template
    line + match[0].count("\n")
  rescue SystemCallError, ArgumentError
    nil
  end

  # Attach source context at the Jekyll boundary that still knows the input.
  # Preserve the innermost context if an exception propagates through wrappers.
  def self.context(error, path, **details)
    error.instance_variable_set(:@builder_error_context, { path: path, **details }) unless
      error.instance_variable_defined?(:@builder_error_context)
  end

  def self.install
    Jekyll::Renderer.prepend(Module.new do
      def render_liquid(content, payload, info, path = nil)
        super
      rescue StandardError, ScriptError => error
        JekyllForkErrorAnalyzer.context(error, path || document.path, content: content)
        raise
      end

      def convert(content)
        super
      rescue StandardError, ScriptError => error
        JekyllForkErrorAnalyzer.context(error, document.path)
        raise
      end
    end)

    Jekyll::Configuration.prepend(Module.new do
      def read_config_file(file)
        super
      rescue StandardError, ScriptError => error
        JekyllForkErrorAnalyzer.context(error, file)
        raise
      end
    end)

    Jekyll::DataReader.prepend(Module.new do
      def read_data_file(path)
        super
      rescue StandardError, ScriptError => error
        JekyllForkErrorAnalyzer.context(error, path)
        raise
      end
    end)

    Jekyll::Convertible.prepend(Module.new do
      def read_yaml(base, name, opts = {})
        super
      rescue Psych::SyntaxError => error
        JekyllForkErrorAnalyzer.context(error, @path || site.in_source_dir(base, name), front_matter: true)
        raise
      end
    end)

    # The CLI normally turns FatalException into SystemExit, losing the object.
    # Keep the original exception for the request's structured diagnostic.
    Jekyll::Commands::Build.singleton_class.prepend(Module.new do
      def process_site(site)
        site.process
      end
    end)
  end
end
