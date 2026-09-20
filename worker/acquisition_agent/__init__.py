"""Claude Agent SDK sidecar for the acquisition platform.

The Agent judges; every action goes through the Laravel Tool bridge
(`php artisan acquisition:tool`). Nothing in this package touches the
network or the database directly (ADR-0001).
"""
