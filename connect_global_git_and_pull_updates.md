# Connect Global Git and Pull Updates

## 1. Trusting the directory as root

When Git runs as root, it may refuse to operate unless the repository directory is configured as safe.

To trust a specific directory:

```bash
git config --global --add safe.directory /home/statystore-ick/htdocs/ick.statystore.online
```

To trust all repositories (only use on trusted personal servers):

```bash
git config --global --add safe.directory '*'
```

Verify the configured safe directories:

```bash
git config --global --get-all safe.directory
```

---

## 2. Clone into the current folder

If the folder is currently empty and you want to clone the repository directly into it:

```bash
rm -rf .git
ls -la
git clone https://github.com/shumbushojeanbaptiste/magazine.eaur.ac.rw.git .
```

The final `.` tells Git to clone into the current directory instead of creating a new subfolder.

---

## 3. If the folder already contains files

If you already have application files in the directory, do not clone into it directly. Instead initialize Git and connect to the existing remote:

```bash
git init
git remote add origin https://github.com/shumbushojeanbaptiste/magazine.eaur.ac.rw.git
git fetch origin
git checkout -t origin/main
```

If the above command is not available or you prefer a newer command:

```bash
git switch -c main --track origin/main
```

---

## 4. Pull updates going forward

After the repository is set up, update it with:

```bash
git pull origin main --no-rebase
```

If you want `git pull` to use merge instead of rebase by default:

```bash
git config --global pull.rebase false
```

Then future updates become:

```bash
git pull
```

---

## 5. Recommended one-time global setup

Set your Git identity and defaults once:

```bash
git config --global user.name "Jean Baptiste SHUMBUSHO"
git config --global user.email "your-email@example.com"
git config --global init.defaultBranch main
git config --global pull.rebase false
git config --global --add safe.directory /home/statystore-ick/htdocs/ick.statystore.online
```

---

## 6. Clean deployment example

For a clean deployment into `/home/statystore-ick/htdocs/ick.statystore.online`:

```bash
cd /home/statystore-ick/htdocs/ick.statystore.online
rm -rf .git
git clone https://github.com/shumbushojeanbaptiste/magazine.eaur.ac.rw.git .
```

Then update later with:

```bash
git pull origin main --no-rebase
```

This keeps the project files directly inside `/home/statystore-ick/htdocs/ick.statystore.online` and allows future updates with `git pull`.
