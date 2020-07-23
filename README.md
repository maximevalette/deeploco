# DeepLoco

## Installation

A simple Symfony 5 CLI app:

```
composer install
```

Then create a `.env.local` to add your LOCO_KEY and DEEPL_KEY API keys.

## Usage

```
bin/console app:translate -h

Description:
  Translate localized content

Usage:
  app:translate [options]

Options:
      --from[=FROM]     From language code [default: "en"]
      --to[=TO]         To language code [default: "fr"]
      --no-fuzzy        Do not flag automatic translations as fuzzy
```