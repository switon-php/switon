#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

usage() {
    cat <<'EOF'
Usage:
  bash build/split.sh gate [package...]
  bash build/split.sh publish [--remote-base <remote-base>] <package:vX.Y.Z>

Commands:
  gate      Run monorepo release checks for one or more packages. With no package list, checks all publishable packages.
  publish   Gate one package release target, resolve its package tag target, then publish its subtree via git subtree.

Notes:
  publish pushes the split package branch to <remote-base>/{name}.git.
  Default remote base: git@github.com:switon-php
  publish requires exactly one release target such as core:v1.0.1.
  publish resolves the monorepo package tag target from the latest tracked commit under packages/{name}/.
  publish creates the split branch and final tags only after split/push succeeds.
  publish creates final tags only after split/push succeeds:
  - monorepo tag: {package}-vX.Y.Z
  - split package tag: vX.Y.Z
  publish replaces existing matching tags when the split needs to be regenerated.
EOF
}

step() {
    printf '==> %s\n' "$1"
}

fail() {
    printf 'split.sh: %s\n' "$1" >&2
    exit 1
}

run_gate() {
    local packages=()

    if (($# > 0)); then
        packages=("$@")
    else
        while IFS= read -r package; do
            packages+=("$package")
        done < <(find "$REPO_ROOT/packages" -mindepth 1 -maxdepth 1 -type d -exec basename {} \; | sort)
    fi

    local package
    for package in "${packages[@]}"; do
        step "Monorepo gate: $package"
        bash "$REPO_ROOT/build/release-check.sh" "$package" --fast
        printf '\n'
    done
}

build_split_target() {
    local package="$1"
    local remote_base="$2"

    if [[ ! -d "$REPO_ROOT/packages/$package" ]]; then
        printf 'split.sh: unknown package: %s\n' "$package" >&2
        exit 1
    fi

    printf 'packages/%s:%s/%s.git' "$package" "$remote_base" "$package"
}

build_split_remote_url() {
    local package="$1"
    local remote_base="$2"

    printf '%s/%s.git' "$remote_base" "$package"
}

package_tag_target_commit() {
    local package="$1"
    local commit=''

    commit="$(git -C "$REPO_ROOT" rev-list -1 HEAD -- "packages/$package")"
    [[ -n "$commit" ]] || fail "unable to resolve package tag target under packages/$package"
    printf '%s\n' "$commit"
}

parse_release_target() {
    local release_target="$1"

    if [[ "$release_target" != *:* ]]; then
        printf 'split.sh: invalid release target (expected package:vX.Y.Z): %s\n' "$release_target" >&2
        exit 1
    fi

    RELEASE_PACKAGE="${release_target%%:*}"
    RELEASE_VERSION="${release_target#*:}"

    if [[ -z "$RELEASE_PACKAGE" || -z "$RELEASE_VERSION" ]]; then
        printf 'split.sh: invalid release target (expected package:vX.Y.Z): %s\n' "$release_target" >&2
        exit 1
    fi

    if [[ "$RELEASE_VERSION" != v* ]]; then
        printf 'split.sh: version must start with v: %s\n' "$RELEASE_VERSION" >&2
        exit 1
    fi
}

current_branch() {
    local branch
    branch="$(git -C "$REPO_ROOT" branch --show-current)"
    [[ -n "$branch" ]] || fail "publish requires a checked-out branch"
    printf '%s\n' "$branch"
}

tag_exists_local() {
    local tag_name="$1"
    git -C "$REPO_ROOT" show-ref --verify --quiet "refs/tags/$tag_name"
}

tag_exists_remote() {
    local remote_name="$1"
    local tag_name="$2"
    git -C "$REPO_ROOT" ls-remote --exit-code --tags "$remote_name" "refs/tags/$tag_name" >/dev/null 2>&1
}

tag_exists_remote_url() {
    local remote_url="$1"
    local tag_name="$2"
    git -C "$REPO_ROOT" ls-remote --exit-code --tags "$remote_url" "refs/tags/$tag_name" >/dev/null 2>&1
}

delete_local_tag() {
    local tag_name="$1"
    git -C "$REPO_ROOT" tag -d "$tag_name" >/dev/null 2>&1 || true
}

delete_remote_tag() {
    local remote_name="$1"
    local tag_name="$2"
    git -C "$REPO_ROOT" push "$remote_name" ":refs/tags/$tag_name"
}

ensure_tag_absent() {
    local scope="$1"
    local exists="$2"
    local tag_name="$3"

    if [[ "$exists" == "yes" ]]; then
        fail "$scope tag already exists: $tag_name"
    fi
}

split_commit_for_package() {
    local package="$1"
    git -C "$REPO_ROOT" subtree split -q --prefix="packages/$package" HEAD
}

push_split_branch() {
    local remote_url="$1"
    local split_commit="$2"

    git -C "$REPO_ROOT" push --force "$remote_url" "${split_commit}:refs/heads/main"
}

create_release_tags() {
    local package="$1"
    local version="$2"
    local remote_url="$3"
    local dry_run="$4"
    local mono_commit="$5"
    local replace_mode="${6:-no}"
    local mono_tag="${package}-${version}"
    local split_tag="$version"
    local split_commit=''
    local exists='no'

    if tag_exists_local "$mono_tag" || tag_exists_remote origin "$mono_tag" || tag_exists_remote_url "$remote_url" "$split_tag"; then
        if [[ "$replace_mode" != "yes" ]]; then
            fail "release tags already exist; rerun with --replace to overwrite"
        fi
        if [[ "$dry_run" == "yes" ]]; then
            printf 'would delete local tag: %s\n' "$mono_tag"
            printf 'would delete remote tag on origin: %s\n' "$mono_tag"
            printf 'would delete remote tag on %s: %s\n' "$remote_url" "$split_tag"
        else
            delete_local_tag "$mono_tag"
            delete_remote_tag origin "$mono_tag"
            delete_remote_tag "$remote_url" "$split_tag"
        fi
    fi

    split_commit="$(split_commit_for_package "$package")"
    [[ -n "$split_commit" ]] || fail "unable to resolve split commit for $package"

    if [[ "$dry_run" == "yes" ]]; then
        printf 'would create monorepo tag: %s (%s)\n' "$mono_tag" "$mono_commit"
        printf 'would push monorepo tag to origin: %s\n' "$mono_tag"
        printf 'would push split tag to %s: %s (%s)\n' "$remote_url" "$split_tag" "$split_commit"
        return
    fi

    git -C "$REPO_ROOT" tag "$mono_tag" "$mono_commit"
    git -C "$REPO_ROOT" push origin "refs/tags/$mono_tag"
    git -C "$REPO_ROOT" push "$remote_url" "${split_commit}:refs/tags/${split_tag}"
}

publish_packages() {
    local remote_base='git@github.com:switon-php'
    local release_target=''
    local split_remote_url=''
    local dry_run='no'
    local replace_mode='no'
    local mono_commit=''
    local split_commit=''

    while (($# > 0)); do
        case "$1" in
            --remote-base)
                shift
                if (($# == 0)); then
                    printf '%s\n' 'split.sh: --remote-base requires a value' >&2
                    exit 1
                fi
                remote_base="$1"
                shift
                ;;
            --remote-base=*)
                remote_base="${1#--remote-base=}"
                shift
                ;;
            --dry-run|-n)
                dry_run='yes'
                shift
                ;;
            --replace)
                replace_mode='yes'
                shift
                ;;
            -*)
                printf 'split.sh: unsupported publish option: %s\n' "$1" >&2
                exit 1
                ;;
            *)
                if [[ -n "$release_target" ]]; then
                    printf '%s\n' 'split.sh: publish accepts exactly one release target' >&2
                    exit 1
                fi
                release_target="$1"
                shift
                ;;
        esac
    done

    if [[ -z "$release_target" ]]; then
        printf '%s\n' 'split.sh: publish requires one release target like core:v1.0.1' >&2
        exit 1
    fi

    parse_release_target "$release_target"

    step "Release target: $RELEASE_PACKAGE:$RELEASE_VERSION"
    run_gate "$RELEASE_PACKAGE"

    mono_commit="$(package_tag_target_commit "$RELEASE_PACKAGE")"
    step "Package tag target: $RELEASE_PACKAGE"
    printf '%s\n\n' "$mono_commit"

    split_remote_url="$(build_split_remote_url "$RELEASE_PACKAGE" "$remote_base")"
    step "Split publish: $split_remote_url"
    split_commit="$(split_commit_for_package "$RELEASE_PACKAGE")"
    [[ -n "$split_commit" ]] || fail "unable to resolve split commit for $RELEASE_PACKAGE"
    if [[ "$dry_run" == "yes" ]]; then
        printf 'would push split branch to %s: main (%s)\n' "$split_remote_url" "$split_commit"
    else
        push_split_branch "$split_remote_url" "$split_commit"
    fi

    step "Final tags: ${RELEASE_PACKAGE}-${RELEASE_VERSION} and ${RELEASE_VERSION}"
    create_release_tags "$RELEASE_PACKAGE" "$RELEASE_VERSION" "$split_remote_url" "$dry_run" "$mono_commit" "$replace_mode"
}

command_name="${1:-}"

case "$command_name" in
    sync-ci)
        shift
        bash "$REPO_ROOT/build/sync-package-ci.sh" "$@"
        ;;
    gate)
        shift
        run_gate "$@"
        ;;
    publish)
        shift
        publish_packages "$@"
        ;;
    -h|--help|"")
        usage
        ;;
    *)
        printf 'Unknown command: %s\n\n' "$command_name" >&2
        usage >&2
        exit 1
        ;;
esac
