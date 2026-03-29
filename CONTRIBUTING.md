# Contributing

Contributions are welcome. Here's how to get started.

## Development setup

1. Fork this repo
2. Clone your fork
3. Copy the extension you're working on into your FreshRSS `extensions/` directory
4. Make changes and reload the FreshRSS page to test

Each `xExtension-*` directory is self-contained. No build step required — PHP and JS run directly.

## Extension structure

Every extension needs:

```
xExtension-Name/
  metadata.json     # Name, version, entrypoint, description
  extension.php     # PHP class extending Minz_Extension
  static/
    script.js       # Client-side behavior
    style.css       # Styles (optional)
  configure.phtml   # Settings UI (optional)
```

## Code style

- Plain JavaScript (no frameworks, no transpilation)
- PHP compatible with FreshRSS's minimum PHP version
- IIFE pattern for JS to avoid global scope pollution
- Use FreshRSS's built-in hooks and APIs where possible

## Testing

Test against the current stable FreshRSS release. Note which version and browser you tested with in your PR.

## Pull requests

- One extension per PR unless changes are tightly coupled
- Describe what the change does and why
- Include the FreshRSS version and browser you tested with
