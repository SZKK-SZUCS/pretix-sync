# CLAUDE.md — pretix-sync

WordPress/WooCommerce plugin: jegyvásárlás (WooCommerce) → pretix. Résztvevőnkénti adatok a checkouton, egy QR pass
(reusable medium) résztvevőnként, opcionálisan session-regisztráció (pretix fiók + membership), badge jegyek és
staff/komp jegyek. Egy aktív esemény telepítésenként. Lásd a megosztott `wp-plugin-conventions` skillt.

A pretix API token kizárólag `PRETIX_SYNC_TOKEN` konstansból / környezeti változóból / Docker secretből jön
(a beállítás-mező csak végső fallback). Eseményspecifikus érték (URL, organizer, event slug, dátumok, szövegek) **nem**
kerülhet a kódba — a beállításokból jön.

## Felépítés
- `pretix-sync.php` — header, autoload, PUC (self-update), require-lánc.
- `includes/` — `class-settings` (beállítások, modulkapcsolók), `class-api` (pretix kliens), `class-sync` (rendelés →
  pretix), `class-webhook`, `class-mailer` (szerkeszthető e-mail sablonok), `class-i18n` (nyelv, Polylang),
  `class-checkout`, `class-comp` (staff/komp + CSV), `class-admin`, `class-attendees`.
- `languages/` — `.pot`, magyar `.po`/`.mo`. A pretix oldali pár: `pretix-session-overlap` (külön repó).

## Parancsok
- `composer install` — dev-eszközök + PUC (a `vendor/` nincs a repóban).
- `composer lint` (phpcs WPCS), `composer stan` (phpstan level 5, a `phpstan-baseline.neon` a nem stubolt
  WooCommerce / Action Scheduler / Polylang szimbólumokat nyeli el; új hiba így is jelez).
- `python tools/build-i18n.py` — `.pot` újragenerálása, `.mo` fordítás, hibával kilép, ha egy string hiányzik a `.po`-ból.
  Új látható string → `__()` + magyar fordítás a `languages/pretix-sync-hu_HU.po`-ban.

## Kiadás
1. Verzió bump a plugin header `Version:` sorában **és** a `PRETIX_SYNC_VERSION` konstansban.
2. `git tag vX.Y.Z && git push --tags` → `.github/workflows/release.yml`: ellenőrzi, hogy a tag = header = konstans,
   ellenőrzi a fordításokat, `composer install --no-dev`, zip (`.distignore` szerint), GitHub Release.
3. A PUC ebből a zipből frissít élesben. A repó neve legyen `pretix-sync` (a PUC URL erre mutat).
