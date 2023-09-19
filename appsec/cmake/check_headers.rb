#!/usr/bin/env ruby

require 'date'

EXCLUSIONS = %w{
  src/extension/version.h
}

base_dir = File.realdirpath('..', __dir__)
files = Dir.glob('{tests,src}/**/*.{cpp,c,hpp,h}', 0, base: base_dir)
           .map { |f| File.realpath(f, base_dir) }


COMMENT = <<-EOF
// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright #{Date.today.year} Datadog, Inc.
EOF

def fix_file(file)
  contents = "#{COMMENT}\n#{File.read file}"
  File.write file, contents
end

Object.instance_eval {
  attr_accessor :has_error
}

def err(file, first_lines)
  STDERR.puts "Bad copyright header in #{file}. First five lines: #{first_lines}"
  if ARGV.size == 1 && ARGV[0] == '--fix'
    fix_file file
  else
    self.has_error = true
  end
end

EXP_TEXT = Regexp.escape(' Unless explicitly stated otherwise all files in this repository are ' \
  'dual-licensed under the Apache-2.0 License or BSD-3-Clause License. ' \
  'This product includes software developed at Datadog (https://www.datadoghq.com/). ' \
  'Copyright XXXX Datadog, Inc.')
  .sub('XXXX', '\d{4}')
  .instance_eval { |s| "\\A#{s}\\z" }
  .instance_eval { |r| Regexp.compile r }

def verify_file(file)
  return if EXCLUSIONS.any? { |e| file.end_with? e }
  File.open(file) do |f|
    first_lines = 5.times.map { f.gets }.map { |s| s&.chomp("\n") }
    first_lines.each do |l|
      unless l&.start_with? '//'
        err file, first_lines
        return
      end
    end
    text = first_lines.map { |l| l[2..] }.join('')
    err(file, text) unless text =~ EXP_TEXT
  end
end

files.each { |f| verify_file f }

exit 1 if has_error
