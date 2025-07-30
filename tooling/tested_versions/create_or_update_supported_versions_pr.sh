#!/usr/bin/env bash
set -euox pipefail

echo "Aggregating tested versions from all test jobs..."
mkdir -p temp_versions
find . -name "tested_versions_*.json" -path "*/artifacts/*" -exec sh -c 'cp "$1" "temp_versions/$(basename "$1" .json)_$(date +%s_%N).json"' _ {} \;
ls -la temp_versions/

jq -s 'reduce .[] as $item ({};
  . as $acc |
  reduce ($item | to_entries[]) as $entry ($acc;
    .[$entry.key] = (.[$entry.key] + $entry.value | unique)
  )
) | to_entries | sort_by(.key) | from_entries' temp_versions/*.json > aggregated_tested_versions.json

echo "Generating markdown table with bash/jq..."
echo "| Library                     | Min. Supported Version | Max. Supported Version |" > integration_versions.md
echo "|-----------------------------|------------------------|------------------------|" >> integration_versions.md

# Process each library and find min/max versions
jq -r 'to_entries | sort_by(.key) | .[] | @base64' aggregated_tested_versions.json | while read encoded; do
  decoded=$(echo $encoded | base64 -d)
  library=$(echo $decoded | jq -r '.key')
  versions=$(echo $decoded | jq -r '.value | join(" ")')

  # Find min and max versions using sort -V (version sort)
  min_version=$(echo $versions | tr ' ' '\n' | sort -V | head -1)
  max_version=$(echo $versions | tr ' ' '\n' | sort -V | tail -1)

  # Format library name to fit table width
  formatted_library=$(printf "%-27s" "$library")
  formatted_min=$(printf "%-22s" "$min_version")
  formatted_max=$(printf "%-22s" "$max_version")

  echo "| $formatted_library | $formatted_min | $formatted_max |" >> integration_versions.md
done

echo "Markdown table generated successfully"
ls -la aggregated_tested_versions.json integration_versions.md
cat integration_versions.md

if [[ -z "$(git status --porcelain)" ]]; then
  echo "No changes detected, exiting."
  exit 0
fi

# Only create PR if on master or alex/ branches (alex/ for testing)
if [[ "${CI_COMMIT_REF_NAME}" == "master" ]] || [[ "${CI_COMMIT_REF_NAME}" =~ ^alex/ ]]; then
  echo "Changes detected, creating/updating PR..."

  CURRENT_BRANCH=${CI_COMMIT_REF_NAME}
  TARGET_BRANCH="update-supported-versions"

  # Setup git remote with token
  git remote remove origin || true
  git remote add origin https://$GITHUB_TOKEN@github.com/DataDog/dd-trace-php.git

  # Check if branch exists and switch to it
  if git ls-remote --heads origin $TARGET_BRANCH | grep $TARGET_BRANCH; then
    echo "Branch exists, updating it..."
    git fetch -f -u origin $TARGET_BRANCH:$TARGET_BRANCH
    git symbolic-ref HEAD refs/heads/$TARGET_BRANCH
    git reset
  else
    echo "Branch does not exist, creating it..."
    git checkout -b $TARGET_BRANCH
  fi

  # Add and commit changes
  git add aggregated_tested_versions.json integration_versions.md
  git commit -m "chore: Update supported versions" --author="github-actions[bot] <41898282+github-actions[bot]@users.noreply.github.com>" || {
    echo "No changes detected, exiting."
    exit 0
  }
  git push origin $TARGET_BRANCH

  # Create or update PR
  PR_NUMBER=$(gh pr list --repo DataDog/dd-trace-php --head $TARGET_BRANCH --json number --jq '.[0].number' || echo "")
  if [[ -z "$PR_NUMBER" ]]; then
    echo "Creating new PR..."
    gh pr create --repo DataDog/dd-trace-php \
      --base $CURRENT_BRANCH \
      --head $TARGET_BRANCH \
      --title "chore: update supported versions" \
      --body "This PR updates the tested versions list automatically."
  else
    echo "A PR already exists."
  fi
else
  echo "Not on master branch, skipping PR creation"
fi
