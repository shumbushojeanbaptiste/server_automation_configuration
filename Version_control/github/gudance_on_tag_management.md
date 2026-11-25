# Git Tag Management Guide

## 1. Introduction

Git tags mark specific commits in a repository. They are commonly used to identify software releases such as:

- `v1.0.0` — First stable release
- `v1.0.1` — Bug fixes
- `v1.1.0` — New features
- `v2.0.0` — Major or breaking changes

This guide explains how to manage Git tags using PowerShell, Git, and GitHub.

## 2. Tag naming convention

Use Semantic Versioning (SemVer):

`MAJOR.MINOR.PATCH`

| Component | Meaning | Example |
|---|---|---|
| MAJOR | Breaking changes | `v2.0.0` |
| MINOR | Backward-compatible features | `v1.1.0` |
| PATCH | Backward-compatible bug fixes | `v1.0.1` |

## 3. Check the current repository

Open PowerShell in your project directory.

```powershell
cd "C:\Users\Dev Jean\GO\central-sms-platform"
```

Check the current branch:

```powershell
git branch --show-current
```

Check the configured remote:

```powershell
git remote -v
```

Check the working tree:

```powershell
git status
```

Before creating a release tag, ensure the intended changes are committed and pushed.

## 4. Create a tag

### Option A: Create an annotated tag (recommended)

Annotated tags include a message and metadata about the tag.

```powershell
git tag -a v1.0.0 -m "Initial stable release"
```

### Option B: Create a lightweight tag

Lightweight tags are simple references to commits without annotation metadata.

```powershell
git tag v1.0.0
```

**Recommendation:** Use annotated tags for releases.

## 5. Tag a specific commit

To tag a particular commit instead of the current `HEAD`:

```powershell
git log --oneline
git tag -a v1.0.0 <commit-hash> -m "Initial stable release"
```

Replace `<commit-hash>` with the actual commit hash.

## 6. List and inspect tags

List all local tags:

```powershell
git tag
```

List tags with their annotation messages:

```powershell
git tag -n
```

Inspect a specific tag:

```powershell
git show v1.0.0
```

List tags in version order:

```powershell
git tag --sort=-version:refname
```

## 7. Push a tag to GitHub

Push one tag:

```powershell
git push origin v1.0.0
```

Push all local tags:

```powershell
git push origin --tags
```

**Best practice:** Push a specific tag when you want to publish only one release.

## 8. Download tags from GitHub

Fetch tags from the remote repository:

```powershell
git fetch origin --tags
```

List remote tags:

```powershell
git ls-remote --tags origin
```

Check a specific remote tag:

```powershell
git ls-remote --tags origin v1.0.0
```

## 9. Delete a local tag

To delete a tag from your local machine:

```powershell
git tag -d v1.0.0
```

This does not delete the tag on GitHub.

## 10. Delete a remote tag

To delete a tag from GitHub:

```powershell
git push origin --delete v1.0.0
```

This does not automatically delete the local tag.

Verify removal:

```powershell
git ls-remote --tags origin v1.0.0
```

No matching output means the remote tag is no longer present.

## 11. Replace an existing remote tag

Sometimes a tag points to the wrong commit or was created incorrectly.

First inspect the local and remote tags:

```powershell
git show v1.0.0
git ls-remote --tags origin v1.0.0
```

If replacement is intentional, delete the remote tag and push the corrected local tag:

```powershell
git push origin --delete v1.0.0
git push origin v1.0.0
```

If you also need to recreate the local tag:

```powershell
git tag -d v1.0.0
git tag -a v1.0.0 -m "Corrected release tag"
git push origin v1.0.0
```

**Warning:** Do not replace published release tags casually. Other developers, deployment workflows, or release assets may depend on the original tag.

## 12. Fix common errors

### Error A: Tag already exists locally

Example:

```text
fatal: tag 'v1.0.0' already exists
```

Inspect the existing tag:

```powershell
git show v1.0.0
```

If it is incorrect and safe to replace:

```powershell
git tag -d v1.0.0
git tag -a v1.0.0 -m "Corrected release tag"
```

### Error B: Tag already exists remotely

Example:

```text
! [rejected] v1.0.0 -> v1.0.0 (already exists)
```

Check the remote tag:

```powershell
git ls-remote --tags origin v1.0.0
```

Either use a new version, such as `v1.0.1`, or intentionally replace the existing remote tag using Section 11.

### Error C: Incorrect push syntax

Incorrect:

```powershell
git push v1.0.0 -m "Release"
```

Correct:

```powershell
git tag -a v1.0.0 -m "Release"
git push origin v1.0.0
```

The `-m` option belongs to `git tag`, not `git push`.

### Error D: Tag points to an older commit

Inspect the tag and latest commits:

```powershell
git show v1.0.0
git log --oneline -5
```

If the tag is incorrect, follow Section 11 to replace it carefully.

## 13. Recommended release workflow

Use the following workflow for each release:

### Step 1: Check the branch and changes

```powershell
git status
git branch --show-current
```

### Step 2: Commit your changes

```powershell
git add .
git commit -m "Prepare release v1.0.0"
```

Skip this step if the changes are already committed.

### Step 3: Push the branch

Replace `main` if your branch has another name.

```powershell
git push origin main
```

### Step 4: Create the release tag

```powershell
git tag -a v1.0.0 -m "Release v1.0.0"
```

### Step 5: Push the tag

```powershell
git push origin v1.0.0
```

### Step 6: Verify the release tag

```powershell
git show v1.0.0
git ls-remote --tags origin v1.0.0
```

### Step 7: Repeat with the next version

For example:

```powershell
git tag -a v1.0.1 -m "Release v1.0.1 - Bug fixes"
git push origin v1.0.1
```

Use a version number appropriate to the changes you are releasing.

## 14. Quick reference

| Task | Command |
|---|---|
| Create annotated tag | `git tag -a v1.0.0 -m "Release"` |
| List local tags | `git tag` |
| Inspect tag | `git show v1.0.0` |
| Push one tag | `git push origin v1.0.0` |
| Push all tags | `git push origin --tags` |
| Fetch remote tags | `git fetch origin --tags` |
| Delete local tag | `git tag -d v1.0.0` |
| Delete remote tag | `git push origin --delete v1.0.0` |
| List remote tags | `git ls-remote --tags origin` |
| List tags by version | `git tag --sort=-version:refname` |

## 15. Best practices

1. Use annotated tags for software releases.
2. Follow Semantic Versioning consistently.
3. Tag commits that have been tested.
4. Push the corresponding branch before publishing its tag.
5. Avoid reusing published version numbers.
6. Prefer creating a new version over replacing a released tag.
7. Verify
