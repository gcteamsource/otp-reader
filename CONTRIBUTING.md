# Contributing to GreatCode Mail Reader

Thank you for considering contributing to the Mail Reader library!

## Development Guidelines

1. **Coding Standard:** Follow PSR-12 coding standard and use strict types (`declare(strict_types=1);`) in every PHP file.
2. **Testing:** All new features or bug fixes must include unit tests. Run `vendor/bin/phpunit` before submitting a pull request.
3. **No External Framework Dependencies:** The core library must remain framework-agnostic and lightweight.

## Submitting Pull Requests

1. Fork the repository.
2. Create a feature branch: `git checkout -b feature/my-feature`.
3. Ensure all tests pass: `vendor/bin/phpunit`.
4. Commit your changes and open a Pull Request.
