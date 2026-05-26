# SSH Key Generation and Setup for Passwordless Access to VPS Server

This guide explains how to generate SSH keys on a VPS server and configure passwordless external access via SSH using RSA keys. This setup allows secure authentication without passwords, suitable for automation and secure remote access.

## Prerequisites

- Root access to the VPS server
- SSH client installed on your local machine
- Basic knowledge of Linux command line

## Overview

We'll generate a single RSA key pair as root and configure it for multiple users on the server. This approach centralizes key management while allowing different users to authenticate using the same key.

## Step-by-Step Instructions

### 1. Generate SSH Key as Root

Connect to your VPS server as root and create the SSH directory:

```bash
mkdir -p /root/.ssh
chmod 700 /root/.ssh
```

Generate the RSA key pair:

```bash
ssh-keygen -t rsa -b 4096 -C "maafricapay-deploy"
```

When prompted:
- Press Enter to save to the default location: `/root/.ssh/id_rsa`
- Leave the passphrase empty (press Enter twice) for passwordless authentication

### 2. Verify Key Generation

Check that the keys were created successfully:

```bash
ls -l /root/.ssh
```

You should see:
- `id_rsa` (private key)
- `id_rsa.pub` (public key)

### 3. Set Up SSH Access for Users

For each user who needs SSH access, perform the following steps. In this example, we'll set up two users: `maafricapay-rayon` and `maafricapay-olee`.

#### Create .ssh Directory for Each User

```bash
mkdir -p /home/maafricapay-rayon/.ssh
mkdir -p /home/maafricapay-olee/.ssh
```

#### Copy Public Key to Authorized Keys

```bash
cat /root/.ssh/id_rsa.pub | tee -a /home/maafricapay-rayon/.ssh/authorized_keys
cat /root/.ssh/id_rsa.pub | tee -a /home/maafricapay-olee/.ssh/authorized_keys
```

#### Set Correct Ownership

```bash
chown -R maafricapay-rayon:maafricapay-rayon /home/maafricapay-rayon/.ssh
chown -R maafricapay-olee:maafricapay-olee /home/maafricapay-olee/.ssh
```

#### Set Correct Permissions

```bash
chmod 700 /home/maafricapay-rayon/.ssh
chmod 600 /home/maafricapay-rayon/.ssh/authorized_keys

chmod 700 /home/maafricapay-olee/.ssh
chmod 600 /home/maafricapay-olee/.ssh/authorized_keys
```

## Testing the Setup

From your local machine, test the SSH connection using the private key:

```bash
ssh -i ~/.ssh/id_rsa maafricapay-rayon@31.97.215.87
ssh -i ~/.ssh/id_rsa maafricapay-olee@31.97.215.87
```

**Note:** Replace `~/.ssh/id_rsa` with the actual path to your private key if it's different, and update the IP address as needed.

## Troubleshooting

### Incorrect Permissions

SSH is very strict about file permissions. If authentication fails, check and fix permissions:

```bash
chmod 700 /home/maafricapay-olee/.ssh
chmod 600 /home/maafricapay-olee/.ssh/authorized_keys
chown -R maafricapay-olee:maafricapay-olee /home/maafricapay-olee/.ssh
```

**Important:** Wrong permissions will cause SSH to silently reject the key.

### SSH Configuration Issues

Check the SSH server configuration:

```bash
nano /etc/ssh/sshd_config
```

Ensure these settings are present and not commented out:
```
PubkeyAuthentication yes
AuthorizedKeysFile .ssh/authorized_keys
```

After making changes, restart the SSH service:

```bash
systemctl restart ssh
```

## Security Notes

- The private key (`id_rsa`) should remain secure and never be shared
- Consider using different keys for different users or purposes in production
- Regularly rotate SSH keys as part of security best practices
- Ensure the SSH service is kept up to date

## Additional Configuration

You can add the following to your local `~/.ssh/config` file for easier connections:

```
Host maafricapay-vps
    HostName 31.97.215.87
    User maafricapay-rayon
    IdentityFile ~/.ssh/id_rsa
```

Then connect with: `ssh maafricapay-vps`	

