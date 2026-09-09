# KomArena MASTER PRODUCT STANDARD

**Version:** MASTER PRO 1.3  
**Status:** Approved reference standard  
**Reference product:** https://komarena.sk/produkt/esp32-devkit-v1-wifi-bluetooth-vyvojova-doska/

## Purpose

This document defines the mandatory product-page standard for KomArena.sk. New products and product refreshes should follow this structure unless a product category genuinely requires a different presentation.

## Core rule

A KomArena product page must be technically trustworthy, visually clean, easy to scan, and useful to both a beginner and an advanced user. Never invent technical parameters. If a value is not confirmed from the real product, label it as unverified or omit it.

## Product gallery

- Use real product photos whenever available.
- Remove background only; do not alter the physical product.
- Keep lighting, angle, crop, scale and background consistent across the gallery.
- Target at least 4 useful product images where possible.
- Gallery images should cover different useful views: main view, alternate angle, detail, label/pinout/connector/detail.
- Do not repeat the same photo unnecessarily.

## MASTER PRO long-description layout

### 1. Intro / hero block
- Short category line / use-case line.
- Clear product headline.
- 2–4 sentence explanation of what the product is, what it is for and any important variant/revision note.

### 2. Editorial image + text block
Use an Alza-style editorial presentation, not a second standalone gallery.

- Image on one side, explanatory text on the other.
- Image must be a real product image.
- The image should support the nearby text.
- Do not place a giant isolated image just for decoration.

### 3. Key-feature cards
Use 3–6 cards depending on the product category.

Examples:
- Wi-Fi / Bluetooth
- capacity / voltage
- interface / logic level
- sensor range
- compatibility / ecosystem
- package quantity

### 4. Second editorial block
Alternate the layout: text on one side, a different product image on the other.

Possible themes:
- real-world use
- compatibility
- connector/detail explanation
- installation note
- revision/variant note

### 5. Technical data table
- Compact and easy to scan.
- Only verified values.
- Responsive/mobile-safe.
- Clearly separate parameter name and value.

### 6. Additional unique image block
If the gallery provides another useful photo, include a third editorial/detail block.

**Mandatory image rule:** every image used inside the long description should be different. Do not repeat the same photo in multiple content blocks when other product images are available.

### 7. Compatibility
List only realistic and verified compatibility, for example:
- ESPHome
- Home Assistant
- Arduino IDE
- PlatformIO
- I2C / SPI / UART / OneWire
- 3.3 V / 5 V as applicable

#### Mandatory Home Assistant compatibility block
Every product must explicitly state its Home Assistant status. This applies to smart-home products and also to electronics, sensors, modules and accessories where Home Assistant use could reasonably be relevant.

Use one of these statuses:
- **Áno – natívna/oficiálna integrácia**: Home Assistant has an official integration or the manufacturer officially documents direct HA support.
- **Áno – lokálna integrácia**: works locally with Home Assistant through a supported local API/protocol/integration. State the integration name and whether cloud access is required.
- **Áno – cez ESPHome/MQTT/bridge**: not directly integrated, but realistically usable through a verified ESPHome, MQTT, Zigbee, Matter, Thread or other supported bridge path. State exactly what is required.
- **Podmienečne**: compatibility depends on firmware, revision, gateway, custom integration or another condition. State the condition clearly.
- **Nie / bez potvrdenej integrácie**: only when reliable sources confirm no practical HA integration.
- **Neoverené**: when available sources are insufficient. Never guess compatibility.

For a positive Home Assistant result, include where useful:
- integration name,
- local vs cloud communication,
- entity type/function exposed in Home Assistant,
- required bridge/gateway/coordinator,
- important limitations,
- link to official Home Assistant documentation when an official integration exists.

Evidence priority for Home Assistant compatibility:
1. official Home Assistant integration documentation,
2. manufacturer documentation/API,
3. verified project documentation such as ESPHome or Zigbee2MQTT,
4. reputable distributor only as supporting evidence, not as the sole source when an official source exists.

For products with official Home Assistant support, the compatibility should be visible in the short description or a prominent key-feature card as well as in the detailed Compatibility section.

### 8. Package contents
Always state exactly what the customer receives.

### 9. Important warning / what to watch out for
Add when relevant:
- voltage/logical-level warnings
- charging/BMS requirements
- revision-specific pinout
- current limits
- mechanical differences
- unverified specification notes

### 10. Who it is for
Where useful, identify the target user:
- beginner
- maker / DIY user
- technician
- smart-home integrator
- service use

### 11. Verified documentation/source
For technical products, link to the most authoritative relevant documentation when possible.

## Short description

The short description should contain:
- what the product is,
- the main benefit/use,
- 2–4 strongest verified parameters,
- one critical compatibility/revision warning if needed,
- Home Assistant compatibility status for smart-home/IoT products or any product where HA use is a meaningful buying criterion.

Avoid long marketing copy.

## SEO standard

Each product should have:
- unique SEO title,
- unique meta description,
- focus keyword,
- useful ALT text on images,
- ItemPage schema where appropriate,
- clean slug.

## Pricing and stock rules

Pricing follows the current KomArena competition/margin policy and must be checked against relevant SK/CZ/PL competition when requested.

Stock must reflect reality:
- physical KomArena stock = normal WooCommerce stock quantity,
- supplier stock must be clearly distinguished from physical KomArena stock,
- do not present supplier inventory as own stock.

## Visual direction

- Clean, premium, modern KomArena identity.
- Turquoise/teal accents, white/light surfaces, restrained borders.
- Strong hierarchy and generous spacing.
- Avoid visual noise and excessive framed boxes.
- Use editorial image + text rhythm similar to leading electronics retailers, while keeping KomArena's own visual identity.
- Mobile-first responsive layout.

## MASTER reference rule

The current approved visual/content reference is the public ESP32 DevKit V1 product page linked at the top of this document.

When improving the MASTER in the future:
1. update the reference product first,
2. review it visually,
3. increment the MASTER PRO version,
4. update this document,
5. only then propagate the change to other products.
