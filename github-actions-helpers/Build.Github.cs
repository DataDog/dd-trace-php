using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text;
using System.Text.RegularExpressions;
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
using Octokit.GraphQL.Model;
using System.Net.Http.Json;
using YamlDotNet.Serialization.NamingConventions;
using ProductHeaderValue = Octokit.ProductHeaderValue;
using static Octokit.GraphQL.Variable;
using Milestone = Octokit.Milestone;
using Release = Octokit.Release;
using Nuke.Common.Utilities.Collections;

partial class Build
{
    [Parameter("A GitHub token (for use in GitHub Actions)", Name = "GITHUB_TOKEN")]
    readonly string GitHubToken;

    [Parameter("Git repository name", Name = "GITHUB_REPOSITORY_NAME", List = false)]
    readonly string GitHubRepositoryName = "dd-trace-php";

    [Parameter("The Pull Request number for GitHub Actions")]
    readonly int? PullRequestNumber;

    [Parameter("The git branch to use", List = false)]
    readonly string TargetBranch;

    const string GitHubRepositoryOwner = "DataDog";

    Target AssignPullRequestToMilestone => _ => _
       .Unlisted()
       .Requires(() => GitHubRepositoryName)
       .Requires(() => GitHubToken)
       .Requires(() => PullRequestNumber)
       .Executes(async() =>
        {
            var client = GetGitHubClient();

            var currentMilestone = (await client.PullRequest.Get(
                owner: GitHubRepositoryOwner,
                name: GitHubRepositoryName,
                number: PullRequestNumber.Value))
                .Milestone;

            if (currentMilestone != null && currentMilestone.Number != 0) {
                Console.WriteLine($"Pull request {PullRequestNumber} already has a milesotone: {currentMilestone.Title} ({currentMilestone.Number})");
                return;
            }

            var milestone = await GetOrCreateNextMilestone(client);

            Console.WriteLine($"Assigning PR {PullRequestNumber} to {milestone.Title} ({milestone.Number})");

            await client.Issue.Update(
                owner: GitHubRepositoryOwner,
                name: GitHubRepositoryName,
                number: PullRequestNumber.Value,
                new IssueUpdate { Milestone = milestone.Number });

            Console.WriteLine($"PR assigned");
        });

    Target SummaryOfSnapshotChanges => _ => _
           .Unlisted()
           .Requires(() => GitHubRepositoryName)
           .Requires(() => GitHubToken)
           .Requires(() => PullRequestNumber)
           .Requires(() => TargetBranch)
           .Executes(async() =>
            {
                // This assumes that we're running in a pull request, so we compare against the target branch
                var baseCommit = GitTasks.Git($"merge-base origin/{TargetBranch} HEAD").First().Text;

                // This is a dumb implementation that just show the diff
                // We could imagine getting the whole context with -U1000 and show the differences including parent name
                // eg now we show -oldAttribute: oldValue, but we could show -tag.oldattribute: oldvalue
                var changes = GitTasks.Git($"diff --diff-filter=M \"{baseCommit}\" -- */*snapshots*/*.*")
                                   .Select(f => f.Text);

                if (!changes.Any())
                {
                    Console.WriteLine($"No snapshots modified (some may have been added/deleted). Not doing snapshots diff.");
                    return;
                }

                var diffCounts = new Dictionary<string, int>();
                StringBuilder diffsInFile = new();
                List<string> deletions = new();
                List<string> additions = new();
                foreach (var line in changes)
                {
                    // Saves the 'blocks' of changes
                    if (line.StartsWith("- "))
                    {
                        deletions.Add(CleanValue(line));
                    }
                    else if (line.StartsWith("+ "))
                    {
                        additions.Add(CleanValue(line));
                    }
                    else if (deletions.Any() && additions.Any() && !Enumerable.SequenceEqual(deletions, additions))
                    {
                        // We have a change, record it
                        foreach (var deletion in deletions)
                        {
                            diffsInFile.AppendLine($"- {deletion}");
                        }
                        foreach (var addition in additions)
                        {
                            diffsInFile.AppendLine($"+ {addition}");
                        }

                        RecordChange(diffsInFile, diffCounts);

                        deletions.Clear();
                        additions.Clear();
                    }
                }

                var markdown = new StringBuilder();
                markdown.AppendLine("## Snapshots difference summary").AppendLine();
                markdown.AppendLine("The following differences have been observed in committed snapshots. It is meant to help the reviewer.");
                markdown.AppendLine("The diff is simplistic, so please check some files anyway while we improve it.").AppendLine();

                foreach (var diff in diffCounts)
                {
                    markdown.AppendLine($"{diff.Value} occurrences of : ");
                    markdown.AppendLine("```diff");
                    markdown.AppendLine(diff.Key);
                    markdown.Append("```").AppendLine();
                }

                await HideCommentsInPullRequest(PullRequestNumber.Value, "## Snapshots difference");
                await PostCommentToPullRequest(PullRequestNumber.Value, markdown.ToString());

                void RecordChange(StringBuilder diffsInFile, Dictionary<string, int> diffCounts)
                {
                    if (diffsInFile.Length > 0)
                    {
                        var change = diffsInFile.ToString();
                        diffCounts.TryAdd(change, 0);
                        diffCounts[change]++;
                        diffsInFile.Clear();
                    }
                }

                string CleanValue(string value)
                {
                    char[] charsToTrim = { ' ', ',' };
                    string cleaned = value.TrimStart('-', '+').Trim(charsToTrim);

                    string[] keysToReplace = { "start", "duration", "php.compilation.total_time_ms", "process_id" };
                    foreach (var key in keysToReplace)
                    {
                        if (cleaned.Contains(key))
                        {
                            // "key": value => "key": [...] (to avoid these noisy changes)
                            cleaned = $"{key}: [...]";
                        }
                    }

                    return cleaned;
                }
            });

    async Task PostCommentToPullRequest(int prNumber, string markdown)
    {
        Console.WriteLine("Posting comment to GitHub");

        // post directly to GitHub as
        var httpClient = new HttpClient();
        httpClient.DefaultRequestHeaders.Add("Accept", "application/vnd.github.v3+json");
        httpClient.DefaultRequestHeaders.Add("Authorization", $"token {GitHubToken}");
        httpClient.DefaultRequestHeaders.UserAgent.Add(new(new System.Net.Http.Headers.ProductHeaderValue("nuke-ci-client")));

        var url = $"https://api.github.com/repos/{GitHubRepositoryOwner}/{GitHubRepositoryName}/issues/{prNumber}/comments";
        Console.WriteLine($"Sending request to '{url}'");

        var result = await httpClient.PostAsJsonAsync(url, new { body = markdown });

        if (result.IsSuccessStatusCode)
        {
            Console.WriteLine("Comment posted successfully");
        }
        else
        {
            var response = await result.Content.ReadAsStringAsync();
            Console.WriteLine("Error: " + response);
            result.EnsureSuccessStatusCode();
        }
    }

    async Task HideCommentsInPullRequest(int prNumber, string prefix)
    {
        try
        {
            Console.WriteLine("Looking for comments to hide in GitHub");

            var clientId = "nuke-ci-client";
            var productInformation = Octokit.GraphQL.ProductHeaderValue.Parse(clientId);
            var connection = new Octokit.GraphQL.Connection(productInformation, GitHubToken);

            var query = new Octokit.GraphQL.Query()
                       .Repository(GitHubRepositoryName, GitHubRepositoryOwner)
                       .PullRequest(prNumber)
                       .Comments()
                       .AllPages()
                       .Select(issue => new { issue.Id, issue.Body, issue.IsMinimized, });

            var issueComments =  (await connection.Run(query)).ToList();

            Console.WriteLine($"Found {issueComments} comments for PR {prNumber}");

            var count = 0;
            foreach (var issueComment in issueComments)
            {
                if (issueComment.IsMinimized || ! issueComment.Body.StartsWith(prefix))
                {
                    continue;
                }

                try
                {
                    var arg = new MinimizeCommentInput
                    {
                        Classifier = ReportedContentClassifiers.Outdated,
                        SubjectId = issueComment.Id,
                        ClientMutationId = clientId
                    };

                    var mutation = new Mutation()
                                  .MinimizeComment(arg)
                                  .Select(x => new { x.MinimizedComment.IsMinimized });

                    await connection.Run(mutation);
                    count++;

                }
                catch (Exception ex)
                {
                    Logger.Warning($"Error minimising comment with ID {issueComment.Id}: {ex}");
                }
            }

            Console.WriteLine($"Minimised {count} comments for PR {prNumber}");
        }
        catch (Exception ex)
        {
            Logger.Warning($"There was an error trying to minimise old comments with prefix '{prefix}': {ex}");
        }
    }

    Target AssignLabelsToPullRequest => _ => _
       .Unlisted()
       .Requires(() => GitHubRepositoryName)
       .Requires(() => GitHubToken)
       .Requires(() => PullRequestNumber)
       .Executes(async() =>
        {
            var client = GetGitHubClient();

            var pr = await client.PullRequest.Get(
                owner: GitHubRepositoryOwner,
                name: GitHubRepositoryName,
                number: PullRequestNumber.Value);

            // Fixes an issue (ambiguous argument) when we do git diff in the Action.
            GitTasks.Git("fetch origin master:master", logOutput: false);
            var changedFiles = GitTasks.Git("diff --name-only master").Select(f => f.Text);
            var config = GetLabellerConfiguration();
            Console.WriteLine($"Checking labels for PR {PullRequestNumber}");

            var updatedLabels = ComputeLabels(config, pr.Title, pr.Labels.Select(l => l.Name), changedFiles);
            var issueUpdate = new IssueUpdate();
            updatedLabels.ForEach(l => issueUpdate.AddLabel(l));

            try
            {
                await client.Issue.Update(
                    owner: GitHubRepositoryOwner,
                    name: GitHubRepositoryName,
                    number: PullRequestNumber.Value,
                    issueUpdate);
            }
            catch(Exception ex)
            {
                Logger.Warning($"An error happened while updating the labels on the PR: {ex}");
            }

            Console.WriteLine($"PR labels updated");

            HashSet<String> ComputeLabels(LabbelerConfiguration config, string prTitle, IEnumerable<string> labels, IEnumerable<string> changedFiles)
            {
                var updatedLabels = new HashSet<string>(labels);

                foreach(var label in config.Labels)
                {
                    try
                    {
                        if (!string.IsNullOrEmpty(label.Title))
                        {
                            Console.WriteLine("Checking if pr title matches: " + label.Title);
                            var regex = new Regex(label.Title, RegexOptions.Compiled);
                            if (regex.IsMatch(prTitle))
                            {
                                Console.WriteLine("Yes it does. Adding label " + label.Name);
                                updatedLabels.Add(label.Name);
                            }
                        }
                        else if (!string.IsNullOrEmpty(label.AllFilesIn))
                        {
                            Console.WriteLine("Checking if changed files are all located in:" + label.AllFilesIn);
                            var regex = new Regex(label.AllFilesIn, RegexOptions.Compiled);
                            if(!changedFiles.Any(x => !regex.IsMatch(x)))
                            {
                                Console.WriteLine("Yes they do. Adding label " + label.Name);
                                updatedLabels.Add(label.Name);
                            }
                        }
                    }
                    catch(Exception ex)
                    {
                        Logger.Warning($"There was an error trying to check labels: {ex}");
                    }
                }
                return updatedLabels;
            }

           LabbelerConfiguration GetLabellerConfiguration()
           {
               var labellerConfigYaml = RootDirectory / ".github" / "labeller.yml";
               Logger.Information($"Reading {labellerConfigYaml} YAML file");
               var deserializer = new YamlDotNet.Serialization.DeserializerBuilder()
                                 .WithNamingConvention(CamelCaseNamingConvention.Instance)
                                 .IgnoreUnmatchedProperties()
                                 .Build();

               using var sr = new StreamReader(labellerConfigYaml);
               return deserializer.Deserialize<LabbelerConfiguration>(sr);
           }
        });

    private async Task<Milestone> GetOrCreateNextMilestone(GitHubClient gitHubClient)
    {
        var milestoneName = CalculateNextVersion(Version);
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

    private async Task<Milestone> GetMilestone(GitHubClient gitHubClient, string milestoneName)
    {
        Console.WriteLine("Fetching milestones...");
        var allOpenMilestones = await gitHubClient.Issue.Milestone.GetAllForRepository(
                                    owner: GitHubRepositoryOwner,
                                    name: GitHubRepositoryName,
                                    new MilestoneRequest { State = ItemStateFilter.Open });

        return allOpenMilestones.FirstOrDefault(x => x.Title == milestoneName);
    }

    string CalculateNextVersion(string currentVersion)
    {
        Console.WriteLine("Current version is " + currentVersion);
        var parsedVersion = new Version(currentVersion);
        var major = parsedVersion.Major;
        int minor;
        int patch;

        // always do minor version bump on 2.x branch
        minor = parsedVersion.Minor + 1;
        patch = 0;

        var nextVersion = $"{major}.{minor}.{patch}";

        Console.WriteLine("Next version calculated as " + nextVersion);
        return nextVersion;
    }

    GitHubClient GetGitHubClient() =>
    new(new ProductHeaderValue("nuke-ci-client"))
    {
        Credentials = new Credentials(GitHubToken)
    };

    class LabbelerConfiguration
    {
        public Label[] Labels { get; set; }

        public class Label
        {
            public string Name { get; set; }
            public string Title { get; set; }
            public string AllFilesIn { get; set; }
        }
    }
}
