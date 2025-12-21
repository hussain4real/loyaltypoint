<!--
Sync Impact Report:
- Version change: 1.0.0 (Initial)
- Modified principles: Defined I, II, III, IV, V
- Added sections: Technology Stack & Standards, Development Workflow
- Removed sections: None
- Templates requiring updates:
  - .specify/templates/tasks-template.md (✅ updated)
  - .specify/templates/plan-template.md (✅ aligned)
  - .specify/templates/spec-template.md (✅ aligned)
- Follow-up TODOs: None
-->
# Loyalty Point Constitution

## Core Principles

### I. Modern Laravel Standards
All code MUST adhere to Laravel 12 best practices. This includes strict typing, constructor property promotion, and the use of Enum classes. Raw database queries are forbidden; Eloquent MUST be used for all data interactions. Code style is enforced via Laravel Pint.

### II. Comprehensive Testing (Pest)
All features MUST be tested using Pest v4. Feature tests are preferred over Unit tests to ensure end-to-end functionality. Tests MUST cover happy paths, failure scenarios, and edge cases. `RefreshDatabase` and Model Factories MUST be used to ensure test isolation.

### III. Consistent UX & Design
The user interface MUST be built using Tailwind CSS v4 utility classes. Custom CSS is prohibited unless absolutely necessary. All UI components MUST follow established design patterns for layout, spacing, and typography, ensuring a consistent experience across the application.

### IV. Performance & Optimization
Performance is a first-class citizen. N+1 query problems MUST be prevented using eager loading. Database columns used in `WHERE` clauses MUST be indexed. Caching SHOULD be used for expensive operations. Frontend assets MUST be optimized via Vite.

### V. Modular & Standard Architecture
The application MUST follow the standard Laravel directory structure. Form Requests MUST be used for validation; inline controller validation is forbidden. Controllers MUST be kept thin, with business logic delegated to Models or Service classes.

## Technology Stack & Standards

- **Backend**: PHP 8.3+, Laravel 12
- **Frontend**: Blade, Tailwind CSS v4, Vite
- **Testing**: Pest v4
- **Code Style**: Laravel Pint
- **API**: Laravel Sanctum (if applicable)

## Development Workflow

1.  **Spec First**: All features start with a specification in `.specify/specs/`.
2.  **Test Driven**: Write Pest tests before or alongside implementation.
3.  **Linting**: Run `pint` before committing.
4.  **Review**: All code must pass automated tests and adhere to this constitution before merge.

## Governance

This Constitution supersedes all other project documentation. Amendments require a Pull Request with a clear rationale and must be approved by the project maintainers. All Pull Requests must verify compliance with these principles.

**Version**: 1.0.0 | **Ratified**: 2025-12-19 | **Last Amended**: 2025-12-19
