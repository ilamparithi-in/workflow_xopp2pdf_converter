# Automated XOPP 2 PDF conversion

> Please note:
> This app is based on [Automated PDF conversion](https://github.com/nextcloud/workflow_pdf_converter). Only minimal changes have been made to support .xoj and .xopp files instead. Therefore: Many thanks to the project maintainers of [Automated PDF conversion](https://github.com/nextcloud/workflow_pdf_converter)!

## Overview

This app automatically converts `.xopp` and `.xoj` files into PDFs using Nextcloud Flow. This allows you to create Xournal(++) files on your PC/tablet, and whenever they are synced to Nextcloud, you can view the rendered PDF version anywhere (mobile app, web, etc.).

## Features

* **Configurable Conversion Modes:**
  * Keep original, preserve existing PDFs (appends `(1)`, `(2)` conflict counter).
  * Keep original, overwrite existing PDF.
  * Delete original, preserve existing PDFs.
  * Delete original, overwrite existing PDF.
* **Granular Folder Opt-Out (`.noconvert` / `.noexport`):**
  * Simply drop an empty `.noconvert` or `.noexport` file into any folder to skip converting all notes in that folder and all its subfolders.
* **Custom Output Directories:**
  * **Same directory:** Leave blank to save alongside the `.xopp` file.
  * **Relative subfolder:** e.g. `PDFs` or `./Exports` (automatically created).
  * **Absolute root folder:** e.g. `/Exports/PDFs` (resolved from the user's files root).
* **Dynamic File Naming Patterns:**
  * Configure custom patterns with runtime tokens:
    * `{filename}` – Original note name without extension
    * `{date}` – Current conversion date (`YYYY-MM-DD`)
    * `{datetime}` – Current conversion timestamp (`YYYY-MM-DD_HH-mm-ss`)
    * `{year}`, `{month}`, `{day}` – Date components
    * `{parent}` – Immediate parent folder name
    * `{user}` – Nextcloud user ID
  * Example: `{filename}_{date}.pdf` or `[Export] {filename}.pdf`.

## Installation & Server Requirements

### 1. Backend Server / Container Dependencies

Install `xournalpp` and necessary font packages on your server:

#### Bare Metal / Debian / Ubuntu:
```bash
apt update
apt install -y xournalpp fonts-liberation fonts-dejavu
```

#### Nextcloud All-in-One (AIO / Alpine Docker):
In your Nextcloud AIO `docker-compose.yml`, specify the following under environment for the mastercontainer:
```yaml
environment:
  - NEXTCLOUD_ADDITIONAL_APKS=imagemagick xournalpp fontconfig font-liberation font-dejavu font-noto
```
*(Font packages ensure that text and formulas rendered inside the container match desktop fonts and do not fall back to generic tofu boxes).*

### 2. App Installation

1. Compile production assets:
   ```bash
   npm install
   npm run build
   ```
2. Copy the app folder to your Nextcloud `custom_apps` directory (naming it `workflow_xopp2pdf_converter`):
   ```bash
   docker cp . nextcloud-aio-nextcloud:/var/www/html/custom_apps/workflow_xopp2pdf_converter
   docker exec -u 0 nextcloud-aio-nextcloud chown -R www-data:www-data /var/www/html/custom_apps/workflow_xopp2pdf_converter
   ```
3. Enable the app via CLI or Nextcloud Apps Page:
   ```bash
   docker exec -u www-data nextcloud-aio-nextcloud php occ app:enable workflow_xopp2pdf_converter
   ```

### 3. Setting Up the Workflow (Flow)

1. Go to **Administration Settings** $\to$ **Flow**.
2. Create a new rule:
   * **When:** `File created` or `File changed`
   * **And:** `File name` $\to$ `matches` $\to$ `/^.+\.(xoj|xopp)$/i`
   * **Action:** `XOPP 2 PDF conversion`
3. Configure your desired mode, target directory, and naming pattern.

## Limitations

* Encrypted files are not supported.

## Thanks

Thanks again to Xournal, Xournal++, Nextcloud and Automated PDF conversion for all their hard work!
