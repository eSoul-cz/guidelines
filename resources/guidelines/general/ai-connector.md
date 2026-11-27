---
title: AI connector
order: 52
summary: Rules for an application that offers an AI connector (a remote MCP server for a client's Claude or ChatGPT) - opt-in switch, OAuth and scopes, tools that act as the user, forbidden tools, draft-only content changes, uploads, audit, personal data, tests and the client guide.
---
# Project guidelines - AI connector

These rules hold for every application that lets a client work with it through their own AI assistant: a remote MCP server reached over HTTPS and authorised by the user with OAuth. They implement ADR-0004 (proposed, esoul-infrastructure PR #3); until it is accepted, a project adopts them knowingly. An application without a connector is out of scope. MUST, MUST NOT, SHOULD and MAY are used as in RFC 2119.

## Switch, authorization and scopes

- The connector is **opt-in and off by default**: one switch (an environment setting) turns the endpoint and its discovery metadata on. With the switch off the endpoint answers 404.
- Authorization follows the MCP authorization spec: OAuth 2.1, protected-resource metadata, dynamic client registration, a consent screen in the client's language. A client MUST NOT receive a static shared token, an API key or a password to paste into the assistant.
- Scopes are narrow and named by what they allow (for example `content:read`, `content:write`). A tool declares the scope it needs and is refused without it.
- A user sees their connected assistants on a page in the admin and revokes one there. Revoking ends its tokens at once.

## Tools act as the user

- A tool calls an Application-layer command or query, never a model or a raw query. It passes the same policies and validation as the admin form for the same action ([security-and-data.md](security-and-data.md#authorization)).
- The acting user is the one who connected the assistant, taken from the token. A connector never has more rights than that user, and a scope never grants what the user's policy refuses.
- A tool MUST NOT, in any project: change code, templates, configuration or deployment; change users, roles or permissions; delete data permanently; run a raw query; call a third-party system on the client's behalf.

## Content changes

- The AI writes drafts. A new page is created unpublished; a change to published content is stored as a revision that the admin shows and restores in one step.
- Content is built only from the project's fixed component catalogue, validated by the same schema as the admin editor. An unknown component or field is refused, not stored.
- **Publishing stays a human action** in the admin. No tool publishes, builds or deploys the live site.

## Uploads

- A tool accepts only the file types the admin accepts, within size and dimension limits, and requires alternative text for an image.
- A tool fetches a file from a URL only through an allowlist of hosts. It refuses private, loopback and link-local addresses, also after a redirect (SSRF).

## Audit, limits and personal data

- Every tool call is written to an audit log: time, user, client, tool, outcome and redacted arguments. Secrets, tokens and file content are never logged ([security-and-data.md](security-and-data.md#personal-and-client-data)).
- Tool calls are rate limited per user and per client.
- Data a tool returns is sent to the client's AI provider. A tool returns the minimal fields the task needs.
- A project whose tools return personal data records in an ADR the data controller and what each tool returns.

## Tests and client guide

- Every tool has tests for: a call without the scope (refused), a call the user's policy refuses, invalid input (refused, nothing stored) and the happy path. The refusal tests call the MCP endpoint, not only the command.
- A test proves that the switch off hides the endpoint and that no tool is registered for a forbidden action above.
- The project ships a client guide in Czech (`docs/client/ai-connector.md`): the connector URL, steps for Claude and ChatGPT, what the assistant can and cannot do, how to revoke access and where the data goes, including the personal-data sentence above.
