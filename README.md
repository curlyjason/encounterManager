# Encounter Manager

A combat tracker for D&D 5e (2024 rules) that runs on your own computer. It handles:

- Initiative, HP, conditions and rounds
- Monsters joining in waves
- Wound states (Bloodied, Death's Door, Unconscious, Dead)
- A difficulty meter based on the 2024 DMG

It can load your party and search your monster database from **Notion**. Notion is optional: you can type everything in by hand.

Everything is stored on your computer in `data/encounters.sqlite`. Nothing goes anywhere except the Notion requests it makes with your own key.

---

## 1. Install PHP (one time)

Encounter Manager needs **PHP 8.1 or newer** with the **pdo_sqlite** and **curl** extensions.

**Mac**
1. Install Homebrew from <https://brew.sh> (paste the command shown there into Terminal).
2. In Terminal, run `brew install php`.

**Windows**
1. Open PowerShell and run `winget install PHP.PHP.8.3`. You can also download the "VS16 x64 Thread Safe" zip from <https://windows.php.net/download/>, unzip it to `C:\php`, and add `C:\php` to your PATH.
2. In the PHP folder, copy `php.ini-development` to `php.ini`. Open `php.ini` and remove the `;` at the start of these lines:
   ```
   extension_dir = "ext"
   extension=curl
   extension=pdo_sqlite
   ```

**Linux**: `sudo apt install php-cli php-sqlite3 php-curl` (or your distro's equivalent).

## 2. Start it

Unzip the folder anywhere you like, for example in Documents.

- **Mac / Linux**: double-click **`start.command`**.
  - The first time, macOS may say it's from an unidentified developer. If so, right-click the file → **Open** → **Open**.
  - If double-clicking does nothing, open Terminal in the folder and run `chmod +x start.command`, then try again.
- **Windows**: double-click **`start.bat`**.

Your browser opens <http://localhost:8765/>. Leave the black window open while you play; closing it stops the app.

> Already run a local web server (MAMP, XAMPP, Apache)? You can drop the folder in its web root instead. The `.htaccess` files keep `data/` and `lib/` private.

## 3. Setup (first run)

On the first run you land on **Setup**. It walks you through these steps:

1. **Checking your computer.** It confirms PHP and its extensions are in place.
2. **Creating a Notion integration.** This is a private key that lets the app read the databases you choose.
   1. Go to [notion.so/profile/integrations](https://www.notion.so/profile/integrations) and click **New integration**.
   2. Pick your workspace, leave the type as **Internal**, and save.
   3. Tick **Read content** and **Update content**. Setup only needs Update content to add missing columns, and you can untick it afterwards.
   4. Copy the **Internal Integration Secret** and paste it into Setup.
3. **Sharing your databases.** In Notion, open your monster database and each campaign's character database. Click **•••** → **Connections** → add **Encounter Manager**. Sharing a parent page shares everything inside it.
4. **Picking databases and matching columns.** Choose your monster database, tick each campaign's character database, and give each campaign a name. Then tell the app which column holds what. Any of Level, AC, Max HP and Init Mod missing from a character database can be added for you as empty Number columns. Fill them in in Notion afterwards.
5. **Save.**

You can come back any time with **⚙ Setup** at the bottom of the sidebar, for example to add a new campaign.

### Optional: monsters from the 5e Artisanal Database

If you have Sly Flourish's **5e Artisanal Database** (a Patreon reward) unzipped on this computer, monster search can use its 3,000+ monsters instead of a Notion monster database. Your custom monsters in its `monsters/custom` folder are included.

At the bottom of Setup, under **Monsters from the 5e Artisanal Database**, click **Find it**, or type or drag in the folder that holds its `index.html`, `monsters` and `css_js` folders, then click **Use this folder**. Each search result gets a **stat block ↗** link that opens the monster's page; Encounter Manager serves those pages from that folder, so no extra setup is needed. **Stop using it** switches monster search back to Notion. Campaigns and PCs always come from Notion.

### What the Notion databases need

| Characters database (one per campaign) | Monster database |
|---|---|
| Name (the title column), required | Name (the title column), required |
| Level: drives the difficulty meter | CR: drives the difficulty meter and CR search |
| AC, Max HP, Init Mod | AC, Hit Points, Initiative modifier |
| Player, Link to the character sheet (optional) | Type, Source, Legendary (optional) |

Your column names don't have to match these; you match them up during Setup. Number columns work best. Text such as "1/2" or "15 (natural armor)" also works.

## Monster images

Monster art is shared by everyone who uses Encounter Manager. The collection lives with the app on GitHub (`images/monsters`), and every copy picks up new images automatically, without an update.

- **Add art** from any stat block: click **+ Add art** (or **Change** on an existing picture), then drop an image, paste one, or paste its web address. **Find art ↗** opens an image search for that monster.
- **Say where it's from.** Each image needs a license: your own work, CC0, CC BY / CC BY-SA (with the artist's name), AI-generated, or **Personal use only**. Most pictures found online are copyrighted: use Personal use only for those. Personal images stay on your computer and are never shared.
- **Every image is shrunk** to 512 px on its longest side and saved as WebP, usually 5–40 KB.
- **Sharing:** new shareable images are kept on your computer until you send them in. On **Monster images** (in the sidebar), **Download my new images** makes a small zip: send it to whoever maintains Encounter Manager, or attach it to a GitHub issue. Once it's added to the collection, everyone sees it.
- **A whole folder** (for example a token pack you own) can be added at once on the Monster images page. Files are matched to monsters by name.
- Monsters without art show an icon for their creature type (icons by Lorc and Delapouite from game-icons.net, CC BY 3.0).

## Everyday use

- **Add party** loads the campaign's PCs.
- **Refresh PCs** re-reads their numbers after you change them in Notion. Blank Notion values never wipe what you typed in the app.
- **Search monsters** by name and CR, in Notion or the 5e Artisanal Database. Clicking a result fills in the add form; **stat block** shows it first.
- **Stat blocks:** click the book icon next to any monster. Choose how they open with the buttons at the top of the stat block: side bar, expanded row, pop-up or new tab. Your choice is remembered. Full stat blocks come from the 5e Artisanal Database; Notion-only monsters show their numbers and a link to their page.
- **Waiting** keeps monsters out of initiative until you **Bring in** some or all of them.
- **Pause** keeps the round and turn; **Stop** resets combat.
- **Budget ×** on the difficulty meter scales the DMG's XP budgets. 1.5 is the default; 1.0 uses the book numbers.

## Backups and updates

- **Back up** `data/encounters.sqlite` (your encounters) and `lib/config.php` (your settings and Notion key).
- **Update** by replacing every file except the `data/` folder and `lib/config.php`.

## Troubleshooting

| Problem | Fix |
|---|---|
| "The token was rejected" | Copy the Internal Integration Secret again; it starts with `ntn_` or `secret_`. |
| A database isn't listed in Setup | Share it with the integration (step 3), then click **Find my databases** again. |
| "Couldn't add … columns" | Tick **Update content** on the integration, or add the Number columns yourself in Notion. |
| SSL / certificate error on Windows | Use PHP 8.2 or newer, or set `curl.cainfo` in `php.ini` to a downloaded `cacert.pem`. |
| Port 8765 is busy | Mac/Linux: `PORT=8800 ./start.command`. Windows: edit `set PORT=` in `start.bat`. |
| Keep your Notion key private | Don't share `lib/config.php`. To pass the app on, use a fresh copy without it. |
