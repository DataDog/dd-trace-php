using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Threading.Tasks;
using Nuke.Common;
using Nuke.Common.IO;
using Nuke.Common.Tooling;
using Nuke.Common.Tools.Git;
using Octokit;
using Octokit.GraphQL;
using Environment = System.Environment;
using Issue = Octokit.Issue;
using Target = Nuke.Common.Target;
using Logger = Serilog.Log;
using System.Net.Http;
using System.Text;
using Octokit.GraphQL.Model;
using System.Net.Http.Json;
using PrepareRelease;
using ProductHeaderValue = Octokit.ProductHeaderValue;
using Milestone = Octokit.Milestone;
using Release = Octokit.Release;

partial class Build
{
    [Parameter("The new build version to set")]
    readonly string NewVersion;

    [Parameter("Whether the current build version is a prerelease(for packaging purposes)")]
    readonly bool IsPrerelease = false;

    string FullVersion => IsPrerelease ? $"{Version}-prerelease" : Version;

    Target OutputNextVersion => _ => _
       .Unlisted()
       .Requires(() => Version)
       .Executes(() =>
        {
            Console.WriteLine("Current version is " + Version);
            var parsedVersion = new Version(Version);
            var major = parsedVersion.Major;
            int minor;
            int patch;

            // always do minor version bump on 2.x branch
            minor = parsedVersion.Minor + 1;
            patch = 0;

            var nextVersion = $"{major}.{minor}.{patch}";

            Console.WriteLine("Next version calculated as " + FullVersion);
            Console.WriteLine("::set-output name=version::" + nextVersion);
            Console.WriteLine("::set-output name=full_version::" + nextVersion);
            Console.WriteLine("::set-output name=previous_version::" + Version);
            Console.WriteLine("::set-output name=isprerelease::false");
        });

    Target UpdateVersion => _ => _
       .Description("Update the version number for the tracer")
       .Requires(() => Version)
       .Requires(() => NewVersion)
       .Executes(() =>
        {
            if (NewVersion == Version)
            {
                throw new Exception($"Cannot set versions, new version {NewVersion} was the same as {Version}");
            }

            // Source needs to use the _actual_ version
            new SetAllVersions(RootDirectory, Version, NewVersion, IsPrerelease).Run();
        });

    Target OutputCurrentVersionToGitHub => _ => _
       .Unlisted()
       .After(UpdateVersion)
       .Requires(() => Version)
       .Executes(() =>
        {
            Console.WriteLine("Using version to " + FullVersion);
            Console.WriteLine("::set-output name=version::" + Version);
            Console.WriteLine("::set-output name=full_version::" + FullVersion);
            Console.WriteLine("::set-output name=isprerelease::" + (IsPrerelease ? "true" : "false"));
        });

    Target VerifyChangedFilesFromVersionBump => _ => _
       .Unlisted()
       .Description("Verifies that the expected files were changed")
       .After(UpdateVersion, UpdateChangeLog)
       .Executes(() =>
        {
            var expectedFileChanges = new List<string>
            {
                "package.xml",
                "src/DDTrace/Tracer.php",
                "ext/version.h",
                "profiling/Cargo.toml",
                "appsec/CMakeLists.txt",
                "github-actions-helpers/Build.cs",
            };

            Logger.Information("Verifying that all expected files changed...");
            var changes = GitTasks.Git("diff --name-only");
            var stagedChanges = GitTasks.Git("diff --name-only --staged");

            var allChanges = changes
                            .Concat(stagedChanges)
                            .Where(x => x.Type == OutputType.Std)
                            .Select(x => x.Text)
                            .ToHashSet();

            var missingChanges = expectedFileChanges
                                .Where(x => !allChanges.Contains(x))
                                .ToList();

            if (missingChanges.Any())
            {
                foreach (var missingChange in missingChanges)
                {
                    Logger.Error($"::error::Expected change not found in file '{missingChange}'");
                }

                throw new Exception("Some of the expected files were not modified by the version bump");
            }

            // Check if we have _extra_ changes. These might be ok, but we should verify
            var extraChanges = allChanges.Where(x => !expectedFileChanges.Contains(x)).ToList();

            var sb = new StringBuilder();
            if (extraChanges.Any())
            {
                sb.AppendLine("The following files were found to be modified. Confirm that these changes were expected " +
                              "(for example, changes to files in the MSI project are expected if our dependencies have changed).");
                sb.AppendLine();
                foreach (var extraChange in extraChanges)
                {
                    sb.Append("- [ ] ").AppendLine(extraChange);
                }

                sb.AppendLine();
                sb.AppendLine();
            }

            sb.AppendLine("The following files were found to be modified (as expected)");
            sb.AppendLine();
            foreach (var expectedFileChange in expectedFileChanges)
            {
                sb.Append("- [x] ").AppendLine(expectedFileChange);
            }

            sb.AppendLine();
            sb.AppendLine("@DataDog/apm-dotnet");

            // need to encode the notes for use by github actions
            // see https://trstringer.com/github-actions-multiline-strings/
            sb.Replace("%","%25");
            sb.Replace("\n","%0A");
            sb.Replace("\r","%0D");

            Console.WriteLine("::set-output name=release_notes::" + sb.ToString());
        });

    Target GenerateReleaseNotes => _ => _
       .Unlisted()
       .Requires(() => GitHubRepositoryName)
       .Requires(() => GitHubToken)
       .Requires(() => Version)
       .Executes(async () =>
        {
            const string tracer = "Tracer";
            const string profiler = "Profiling";
            const string appSecMonitoring = "Application Security Management";
            const string fixes = "Fixes";
            const string buildAndTest = "Build / Test";
            const string misc = "Miscellaneous";
            const string ciVisibility = "CI Visibility";
            const string debugger = "Debugger";
            const string serverless = "Serverless";

            var nextVersion = FullVersion;
            var client = GetGitHubClient();
            var milestone = await GetMilestone(client, Version);

            Console.WriteLine($"Fetching Issues assigned to {milestone.Title}");
            var issues = await client.Issue.GetAllForRepository(
                             owner: GitHubRepositoryOwner,
                             name: GitHubRepositoryName,
                             new RepositoryIssueRequest
                             {
                                 Milestone = milestone.Number.ToString(),
                                 State = ItemStateFilter.Closed,
                                 SortProperty = IssueSort.Created,
                                 SortDirection = SortDirection.Ascending,
                             });

            Console.WriteLine($"Found {issues.Count} issues, building release notes.");

            var sb = new StringBuilder();

            sb.AppendLine("Write here any high level summary you may find relevant or delete the section.").AppendLine();

            var issueGroups = issues
                             .Select(CategorizeIssue)
                             .GroupBy(x => x.category)
                             .Select(issues =>
                              {
                                  var sb = new StringBuilder($"## {issues.Key}");
                                  sb.AppendLine();
                                  foreach (var issue in issues)
                                  {
                                      sb.AppendLine($"* {issue.issue.Title} (#{issue.issue.Number})");
                                  }

                                  return (order: CategoryToOrder(issues.Key), content: sb.ToString());
                              })
                             .OrderBy(x => x.order)
                             .Select(x => x.content);

            foreach (var issueGroup in issueGroups)
            {
                sb.AppendLine(issueGroup);
            }

            // need to encode the release notes for use by github actions
            // see https://trstringer.com/github-actions-multiline-strings/
            sb.Replace("%","%25");
            sb.Replace("\n","%0A");
            sb.Replace("\r","%0D");

            Console.WriteLine("::set-output name=release_notes::" + sb.ToString());

            Console.WriteLine("Release notes generated");

            static (string category, Issue issue) CategorizeIssue(Issue issue)
            {
                var fixIssues = new[] { "type:bug", "type:regression", "type:cleanup", "🐛 bug" };
                var areaLabelToComponentMap = new Dictionary<string, string>() {
                    { "tracing", tracer },
                    { "area:ci-visibility", ciVisibility },
                    { "area:asm", appSecMonitoring },
                    { "profiling", profiler },
                    { "area:debugger", debugger },
                    { "area:serverless", serverless }
                };

                var buildAndTestIssues = new []
                {
                    "ci",
                    "dev/testing",
                    "cat:devtools"
                };

                foreach((string area, string component) in areaLabelToComponentMap)
                {
                    if (issue.Labels.Any(x => x.Name == area))
                    {
                        return (component, issue);
                    }
                }

                if (issue.Labels.Any(x => fixIssues.Contains(x.Name)))
                {
                    return (fixes, issue);
                }

                if (issue.Labels.Any(x => buildAndTestIssues.Contains(x.Name)))
                {
                    return (buildAndTest, issue);
                }

                return (misc, issue);
            }

            static int CategoryToOrder(string category) => category switch
            {
                tracer => 0,
                ciVisibility => 1,
                appSecMonitoring => 2,
                profiler => 3,
                debugger => 4,
                serverless => 5,
                fixes => 6,
                _ => 7
            };
        });

    Target UpdateChangeLog => _ => _
       .Unlisted()
       .Requires(() => Version)
       .Executes(() =>
        {
            var releaseNotes = Environment.GetEnvironmentVariable("RELEASE_NOTES");
            if (string.IsNullOrEmpty(releaseNotes))
            {
                Logger.Error("::error::Release notes were empty");
                throw new Exception("Release notes were empty");
            }

            Console.WriteLine("Updating changelog...");

            releaseNotes = releaseNotes.TrimEnd('\n');
            var changelogPath = RootDirectory / "package.xml";
            var changelog = File.ReadAllText(changelogPath);

            // find first header
            var firstHeaderIndex = changelog.IndexOf("<notes>");
            var lastHeaderIndex = changelog.IndexOf("</notes>");

            using (var file = new StreamWriter(changelogPath, append: false))
            {
                // write the header
                file.Write(changelog.AsSpan(0, firstHeaderIndex + "<notes>".Length));

                // Write the new entry
                file.WriteLine();
                file.WriteLine($"        <![CDATA[");
                file.WriteLine();
                file.WriteLine(releaseNotes);
                file.Write("]]");

                // Write the remainder
                file.Write(changelog.AsSpan(lastHeaderIndex));
            }
            Console.WriteLine("Changelog updated");
        });

    Target CloseMilestone => _ => _
       .Unlisted()
       .Requires(() => GitHubToken)
       .Requires(() => Version)
       .Executes(async() =>
       {
            var client = GetGitHubClient();

            var milestone = await GetMilestone(client, Version);
            if (milestone is null)
            {
                Console.WriteLine($"Milestone {Version} not found. Doing nothing");
                return;
            }

            Console.WriteLine($"Closing {milestone.Title}");

            try
            {
                await client.Issue.Milestone.Update(
                    owner: GitHubRepositoryOwner,
                    name: GitHubRepositoryName,
                    number: milestone.Number,
                    new MilestoneUpdate { State = ItemState.Closed });
            }
            catch (ApiValidationException ex)
            {
                Console.WriteLine($"Unable to close {milestone.Title}. Exception: {ex}");
                return; // shouldn't be blocking
            }

            Console.WriteLine($"Milestone closed");
        });

    private async Task<Milestone> GetOrCreateCurrentMilestone(GitHubClient gitHubClient)
    {
        var milestoneName = Version;
        var milestone = await GetMilestone(gitHubClient, milestoneName);
        if (milestone is not null)
        {
            Console.WriteLine($"Found {milestoneName} milestone: {milestone.Number}");
            return milestone;
        }

        Console.WriteLine($"{milestoneName} milestone not found, creating");

        var milestoneRequest = new NewMilestone(milestoneName);
        milestone = await gitHubClient.Issue.Milestone.Create(
                   owner: GitHubRepositoryOwner,
                   name: GitHubRepositoryName,
                   milestoneRequest);
        Console.WriteLine($"Created {milestoneName} milestone: {milestone.Number}");
        return milestone;
    }
}
