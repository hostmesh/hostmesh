#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Do the service containers of a workflow run the images of the development
# stack? (issue #181)
#
#   service-images.sh .github/workflows/control-plane.yml infrastructure/development/compose.yaml
#
# Every service container of every job in the workflow must have exactly the
# image of the service with the same name in the compose file. Dependabot
# updates the compose file but not the workflow. Prints one line per service
# container and exits 1 when one differs, 2 when it cannot compare: a missing
# file, YAML that yq cannot read, or a workflow without service containers.
# Needs yq 4 (mikefarah), which the GitHub-hosted Ubuntu runners have.
set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo "usage: service-images.sh WORKFLOW COMPOSE_FILE" >&2
    exit 2
fi
workflow=$1
compose=$2

for file in "$workflow" "$compose"; do
    if [ ! -r "$file" ]; then
        echo "service-images.sh: cannot read $file" >&2
        exit 2
    fi
done
if ! command -v yq > /dev/null; then
    echo "service-images.sh: yq 4 is not installed" >&2
    exit 2
fi

# One "name<TAB>image" line per service container.
if ! containers=$(yq '.jobs[] | select(.services) | .services | to_entries | .[] | .key + "\t" + (.value.image // "")' "$workflow"); then
    echo "service-images.sh: cannot read the jobs of $workflow" >&2
    exit 2
fi
if [ -z "$containers" ]; then
    echo "service-images.sh: $workflow has no service containers" >&2
    exit 2
fi

status=0
while IFS=$'\t' read -r name image; do
    if ! known=$(name=$name yq '(.services // {}) | has(strenv(name))' "$compose") \
        || ! want=$(name=$name yq '.services[strenv(name)].image // ""' "$compose"); then
        echo "service-images.sh: cannot read the services of $compose" >&2
        exit 2
    fi
    if [ "$known" != true ]; then
        echo "$name: $image in $workflow, no such service in $compose"
        status=1
    elif [ -z "$want" ]; then
        echo "$name: $image in $workflow, no image in $compose"
        status=1
    elif [ "$image" != "$want" ]; then
        echo "$name: $image in $workflow, $want in $compose"
        status=1
    else
        echo "$name: $image"
    fi
done <<< "$containers"

exit "$status"
