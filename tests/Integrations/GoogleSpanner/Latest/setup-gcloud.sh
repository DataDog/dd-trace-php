#!/usr/bin/env bash
curl -L https://nixos.org/nix/install | sh -s -- --no-daemon
export PATH=$PATH:~/.nix-profile/bin
nix-env -i google-cloud-sdk
