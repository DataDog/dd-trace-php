// <copyright file="SetAllVersions.cs" company="Datadog">
// Unless explicitly stated otherwise all files in this repository are licensed under the Apache 2 License.
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2017 Datadog, Inc.
// </copyright>

using System;
using System.IO;
using System.Text;
using System.Text.RegularExpressions;

namespace PrepareRelease
{
    public class SetAllVersions
    {
        public SetAllVersions(string rootPath, string tracerVersion, string newTracerVersion, bool isPrerelease)
        {
            TracerVersion = new Version(tracerVersion);
            NewTracerVersion = new Version(newTracerVersion);
            RootPath = rootPath;
            IsPrerelease = isPrerelease;
        }

        /// <summary>
        /// Gets the current tracer version.
        /// </summary>
        public Version TracerVersion { get; }

        /// <summary>
        /// Gets the new tracer version.
        /// </summary>
        public Version NewTracerVersion { get; }

        /// <summary>
        /// Gets a value indicating whether the current tracer version is a prerelease.
        /// </summary>
        public string RootPath { get; }

        public bool IsPrerelease { get; }

        public void Run()
            {
                Console.WriteLine($"Updating source version instances to {NewVersionString()}");

                SynchronizeVersion(
                    "github-actions-helpers/Build.Release.cs",
                    text => Regex.Replace(text, $"readonly string Version = \"{OldVersionString()}\"", $"readonly string Version = \"{NewVersionString()}\""));

                SynchronizeVersion(
                    "src/DDTrace/Tracer.php",
                    text => Regex.Replace(text, $"const VERSION = '{OldVersionString()}'", $"const VERSION = '{NewVersionString()}"));

                SynchronizeVersion(
                    "ext/version.h",
                    text => Regex.Replace(text, $"#define PHP_DDTRACE_VERSION \"{OldVersionString()}\"", $"#define PHP_DDTRACE_VERSION \"{NewVersionString()}\""));

                SynchronizeVersion(
                    "profiling/Cargo.toml",
                    text => Regex.Replace(text, $"version = \"{OldVersionString()}\"", $"version = \"{NewVersionString()}\""));

                SynchronizeVersion(
                    "appsec/CMakeLists.txt",
                    text => Regex.Replace(text, $"project\\(ddappsec VERSION {VersionPattern()}", $"project(ddappsec VERSION {NewVersionString()}"));


                Console.WriteLine($"Completed synchronizing source versions to {NewVersionString()}");
            }

        private string FunctionCallReplace(string text, string functionName)
        {
            const string split = ", ";
            var pattern = @$"{functionName}\({VersionPattern(split, fourPartVersion: true)}\)";
            var replacement = $"{functionName}({FourPartVersionString(split)})";

            return Regex.Replace(text, pattern, replacement, RegexOptions.Singleline);
        }

        private void SynchronizeVersion(string path, Func<string, string> transform)
        {
            var fullPath = Path.Combine(RootPath, path);

            Console.WriteLine($"Updating version instances for {path}");

            if (!File.Exists(fullPath))
            {
                throw new Exception($"File not found to version: {path}");
            }

            var fileContent = File.ReadAllText(fullPath);
            var newFileContent = transform(fileContent);

            File.WriteAllText(fullPath, newFileContent, new UTF8Encoding(encoderShouldEmitUTF8Identifier: false));
        }

        private string FourPartVersionString(string split = ".")
        {
            return $"{TracerVersion.Major}{split}{TracerVersion.Minor}{split}{TracerVersion.Build}{split}0";
        }

        private string NewVersionString(string split = ".", bool withPrereleasePostfix = false)
        {
            return VersionString(NewTracerVersion, split, withPrereleasePostfix);
        }

        private string OldVersionString(string split = ".", bool withPrereleasePostfix = false)
        {
            return VersionString(TracerVersion, split, withPrereleasePostfix);
        }

        private string VersionString(Version version, string split, bool withPrereleasePostfix)
        {
            var newVersion = $"{version.Major}{split}{version.Minor}{split}{version.Build}";

            // this gets around a compiler warning about unreachable code below
            var isPreRelease = IsPrerelease;

            // ReSharper disable once ConditionIsAlwaysTrueOrFalse
            if (withPrereleasePostfix && isPreRelease)
            {
                newVersion = newVersion + "-prerelease";
            }

            return newVersion;
        }

        private string VersionPattern(string split = ".", bool withPrereleasePostfix = false, bool fourPartVersion = false)
        {
            if (split == ".")
            {
                split = @"\.";
            }

            var pattern = $@"\d+{split}\d+{split}\d+";

            if (fourPartVersion)
            {
                pattern = pattern + $@"{split}\d+";
            }

            if (withPrereleasePostfix)
            {
                pattern = pattern + "(\\-prerelease)?";
            }

            return pattern;
        }
    }
}
