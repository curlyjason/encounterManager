# What's new in Encounter Manager

Each release lists what changed. Newest first.

<!-- new releases go below this line -->

## 2.3.0 — 2026-11-25

### New: spell details on hover

Spells in stat blocks are underlined. Hover over one (or tap it) to see the whole spell: level, casting time, range, components, duration and description. Each monster gets the spell from its own book, so a 2024 monster shows the 2024 version. Needs the 5e Artisanal Database.

### New: stat block on each turn

With **Stat block each turn** ticked (next to the turn buttons), Next and Prev show the stat block of whoever's turn it is.

### New: player character blocks

PCs now have a stat block too: level, player, AC, current HP, initiative, conditions and notes, with buttons to open their **character sheet** and their **Notion page**. Sheet links come from your Notion **Link** column when you use **Add party** or **Refresh PCs**, or you can paste one into the block.

### Changed

- Hide the encounter list with **«** (or the **[** key) to give the encounter more room; **»** brings it back.
- **⚙ Setup** stays at the bottom of the sidebar while the page scrolls.

## 2.2.0 — 2026-11-25

### New: generate monster art with AI

- Open **⚙ Setup** and scroll to **Generate monster art with AI**. Paste an OpenAI API key (from platform.openai.com/api-keys), pick the image model and a default quality, and click **Save**.
- On any stat block, click **+ Add art**: a **Generate with AI** box describes the monster for you. Change the description if you like, pick a quality, and click **Generate**.
- You see the picture before anything is saved. Click **Save** to keep it, or **Generate again** to try another.
- Pictures are paid by your own OpenAI account: roughly 1¢ (low), 5¢ (medium) or 20¢ (high) each. You only pay for pictures you generate.
- Generated pictures are labeled AI-generated with the model's name, and can be shared like any other art.
- Your key is stored only on your computer.

### The shared collection has begun

The first monster art is in: **Hobgoblin** and **Mage**. Open their stat blocks to see them.

## 2.1.0 — 2026-11-25

### New: stat blocks

- Click the **book icon** next to any monster (or a waiting group) to see its stat block. In monster search, **stat block** lets you look before you add.
- Show them in a **side bar**, an **expanded row**, a **pop-up** or a **new tab**: switch with the buttons at the top of any stat block. Your choice is remembered.
- Full stat blocks come from the 5e Artisanal Database. Notion-only monsters show their numbers and a link to their Notion page.
- Monsters already in your encounters are matched to their stat block the first time you open them.

### New: monster images, shared by everyone

- Click **+ Add art** on a stat block, then drop an image, paste one, or paste its web address. **Find art ↗** searches for one.
- Every image is shrunk to 512 px and saved as WebP, so each is tiny.
- Say where each image is from. **Personal use only** images stay on your computer; others can be shared.
- Share yours from the new **Monster images** page (in the sidebar): **Download my new images** makes a small zip to send in. Once added to the collection, everyone sees them automatically, without an update.
- You can also add a whole folder of art or tokens at once; files are matched to monsters by name.
- Monsters without art show an icon for their creature type.

## 2.0.0 — 2026-11-25

### New: monsters from the 5e Artisanal Database

If you have Sly Flourish's **5e Artisanal Database** (a Patreon reward) on your computer, monster search can now use its 3,000+ monsters instead of a Notion monster database.

- Open **⚙ Setup** and scroll to **Monsters from the 5e Artisanal Database**. Click **Find it**, or type or drag in its folder, then **Use this folder**.
- Search by name and CR as before. Legendary monsters are marked **LEG**, and your own custom monsters from its `monsters/custom` folder are included.
- Each result has a **stat block ↗** link that opens the monster's full page. Encounter Manager shows these pages itself, so nothing else needs setting up.
- Many monsters appear in several books (the 2014 and 2024 rules, Black Flag, Level Up, Tome of Beasts…). The source under each result tells them apart.

### Changed

- **While a 5e Artisanal Database folder is set, monster search no longer looks in Notion.** Your Notion monster settings are kept; click **Stop using it** in Setup to switch back.
- Campaigns and PCs still come from Notion, exactly as before.

## 1.0.0 — 2026-11-22

The first shared release.

- **Combat tracker:** initiative, HP with temporary HP, conditions, rounds, pause and stop.
- **Notion:** load each campaign's party, refresh their stats, and search your monster database by name and CR.
- **Waves:** keep monsters waiting and bring some or all of them into initiative mid-fight.
- **Wound states:** Bloodied, Death's Door, Unconscious and Dead, with a Fallen list and a red fade when someone dies.
- **Difficulty meter:** 2024 DMG encounter budgets, with an adjustable multiplier (default ×1.5).
- **Setup wizard:** connects Notion, finds your databases, matches columns and adds missing ones.
- **Updates:** the app tells you when a new version is out and installs it in one click.
