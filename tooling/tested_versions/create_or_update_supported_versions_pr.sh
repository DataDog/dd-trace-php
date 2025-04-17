#!/usr/bin/env bash
set -euox pipefail

if [[ -z $(git status --porcelain) ]]; then
  echo "No changes detected, exiting."
  exit 0
fi

git config --global user.email "41898282+github-actions[bot]@users.noreply.github.com"
git config --global user.name "github-actions[bot]"

CURRENT_BRANCH=${CIRCLE_BRANCH}
TARGET_BRANCH="update-supported-versions"

git remote remove origin
git remote add origin https://$GITHUB_TOKEN@github.com/DataDog/dd-trace-php.git

if git ls-remote --heads origin $TARGET_BRANCH | grep $TARGET_BRANCH; then
  echo "Branch exists, updating it..."
  git fetch -f -u origin $TARGET_BRANCH:$TARGET_BRANCH
  git symbolic-ref HEAD refs/heads/$TARGET_BRANCH
  git reset
else
  echo "Branch does not exist, creating it..."
  git checkout -b $TARGET_BRANCH
fi

git add aggregated_tested_versions.json integration_versions.md
git commit -m "chore: Update supported versions" --author="github-actions[bot] <41898282+github-actions[bot]@users.noreply.github.com>" || {
  echo "No changes detected, exiting."
  exit 0
}
git push origin $TARGET_BRANCH

# Install GitHub CLI
curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | sudo dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg
sudo chmod go+r /usr/share/keyrings/githubcli-archive-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | sudo tee /etc/apt/sources.list.d/github-cli.list > /dev/null
sudo apt update && sudo apt install -y gh

# Check if PR already exists
PR_NUMBER=$(gh pr list --repo DataDog/dd-trace-php --head $TARGET_BRANCH --json number --jq '.[0].number')
if [[ -z $PR_NUMBER ]]; then
  echo "Creating new PR..."
  gh pr create --repo DataDog/dd-trace-php \
    --base $CURRENT_BRANCH \
    --head $TARGET_BRANCH \
    --title "chore: update supported versions" \
    --body "This PR updates the tested versions list automatically."
else
  echo "A PR already exists."
fi
