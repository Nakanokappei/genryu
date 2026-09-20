"""The MCP tool surface exposed to the Agent.

Each tool is a thin proxy: validate nothing, interpret nothing, forward the
arguments to the Laravel bridge and return its JSON verbatim. The schemas
below describe the Agent-facing shape (RAW blob references instead of bytes;
host scope and budgets are enforced by Laravel, never by the Agent).
"""

from __future__ import annotations

import json
from typing import Any

from .bridge import ToolBridge

TOOL_SPECS: dict[str, dict[str, Any]] = {
    "discover_web": {
        "description": (
            "Explore one official site within a fixed budget and return stable acquisition routes: "
            "feeds, sitemaps, index pages, pagination, PDF links, plus the exploration graph and failures. "
            "Deterministic; respects robots.txt and the rate limit; never leaves the allowed hosts."
        ),
        "schema": {
            "type": "object",
            "properties": {
                "seed_url": {"type": "string", "description": "Absolute http(s) URL to start from."},
                "max_depth": {"type": "integer", "minimum": 0, "maximum": 5, "description": "Link depth limit (may only tighten the run budget)."},
                "max_urls": {"type": "integer", "minimum": 1, "maximum": 500, "description": "URL fetch limit (may only tighten the run budget)."},
                "max_seconds": {"type": "integer", "minimum": 5, "maximum": 600},
                "hints": {"type": "array", "items": {"type": "string"}, "description": "URL fragments to explore first, e.g. ['/news', '/press-releases']."},
            },
            "required": ["seed_url"],
        },
    },
    "fetch_url": {
        "description": (
            "Fetch one URL byte-for-byte within the run's host scope and store it as an immutable RAW artifact. "
            "Returns response metadata and raw_artifact.blob_uri; never the body. Use the blob_uri with the parse tools."
        ),
        "schema": {
            "type": "object",
            "properties": {
                "url": {"type": "string"},
                "conditional_headers": {
                    "type": "object",
                    "properties": {"etag": {"type": "string"}, "last_modified": {"type": "string"}},
                },
            },
            "required": ["url"],
        },
    },
    "parse_html": {
        "description": (
            "Parse a stored HTML RAW artifact: title, canonical URL, metadata, JSON-LD, date candidates, links, "
            "main-content Markdown, quality metrics."
        ),
        "schema": {
            "type": "object",
            "properties": {
                "raw_blob_uri": {"type": "string", "description": "raw_artifact.blob_uri from fetch_url."},
                "url": {"type": "string", "description": "The final URL the bytes were served from."},
            },
            "required": ["raw_blob_uri", "url"],
        },
    },
    "parse_xml": {
        "description": (
            "Parse a stored RSS/Atom/sitemap RAW artifact: kind, feed metadata, entries (id, url, dates), "
            "sitemap children, pagination, quality metrics."
        ),
        "schema": {
            "type": "object",
            "properties": {
                "raw_blob_uri": {"type": "string"},
                "url": {"type": "string"},
                "expected_kind": {"type": "string", "enum": ["rss", "atom", "sitemap", "sitemap_index"]},
            },
            "required": ["raw_blob_uri", "url"],
        },
    },
    "parse_pdf": {
        "description": "Extract the text layer of a stored PDF RAW artifact page by page. Scanned or encrypted PDFs fail with an explicit code.",
        "schema": {
            "type": "object",
            "properties": {"raw_blob_uri": {"type": "string"}, "url": {"type": "string"}},
            "required": ["raw_blob_uri", "url"],
        },
    },
    "normalize_document": {
        "description": (
            "Turn a parse result into the common Document Model (stable identity, dates, Markdown, quality verdict). "
            "Pass the full parse result object and the RAW blob it came from."
        ),
        "schema": {
            "type": "object",
            "properties": {
                "parsed": {"type": "object", "description": "The complete result object returned by parse_html / parse_xml / parse_pdf."},
                "raw_blob_uri": {"type": "string"},
                "document_type": {"type": "string", "enum": ["news", "program", "report", "feed", "sitemap", "other"]},
                "feed_guid": {"type": "string", "description": "The feed entry GUID when this document came from a feed."},
            },
            "required": ["parsed", "raw_blob_uri"],
        },
    },
    "store_source_profile_candidate": {
        "description": (
            "Store a Source Profile candidate for human approval. The profile must satisfy the v1 JSON Schema "
            "given in your instructions. It is saved as PENDING_APPROVAL; you cannot activate it."
        ),
        "schema": {
            "type": "object",
            "properties": {
                "profile": {"type": "object", "description": "The complete Source Profile document."},
                "change_reason": {"type": "string", "description": "Why this candidate exists, in one or two sentences."},
            },
            "required": ["profile"],
        },
    },
}


def tool_result(response: dict[str, Any], is_error: bool) -> dict[str, Any]:
    """Shape a bridge response as an MCP tool result."""
    return {
        "content": [{"type": "text", "text": json.dumps(response, ensure_ascii=False)}],
        "is_error": is_error,
    }


def build_server(bridge: ToolBridge, allowed: list[str]):
    """Create the in-process MCP server exposing exactly the allowlisted tools."""
    # Imported lazily so the bridge and specs stay testable without the SDK.
    from claude_agent_sdk import create_sdk_mcp_server, tool

    sdk_tools = []

    for name in allowed:
        spec = TOOL_SPECS[name]

        def make_handler(tool_name: str):
            async def handler(args: dict[str, Any]) -> dict[str, Any]:
                response, is_error = bridge.call(tool_name, args)
                return tool_result(response, is_error)

            return handler

        sdk_tools.append(tool(name, spec["description"], spec["schema"])(make_handler(name)))

    return create_sdk_mcp_server(name="acquisition", version="1.0.0", tools=sdk_tools)
