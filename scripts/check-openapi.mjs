#!/usr/bin/env node
/**
 * Keep `docs/api/openapi.yaml` honest.
 *
 * This exists because the specification was not valid YAML for an entire phase and nobody
 * noticed: nothing parsed it, so a plain scalar containing `": "` sat in the file unread until
 * someone tried to load it. A document that is never machine-read is a document that drifts, and
 * an API specification that disagrees with the API is worse than no specification — an integrator
 * builds against it and finds out in production.
 *
 * Three checks, in increasing order of what they need:
 *
 *   1. **It parses**, and every internal `$ref` resolves to something that exists.
 *   2. **Operation ids are unique** and every tag used is declared — the two things generators
 *      break on.
 *   3. **It matches the routes**, in both directions: no endpoint the application serves is
 *      undocumented, and no endpoint is documented that the application does not serve.
 *
 * The third needs `php artisan route:list`, so it is skipped with a stated warning when PHP is
 * not available rather than silently passing. Run it with `--require-routes` in CI, where a skip
 * should be a failure.
 */

import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import yaml from 'js-yaml'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const specPath = resolve(root, 'docs/api/openapi.yaml')
const requireRoutes = process.argv.includes('--require-routes')

const problems = []
const notes = []

// ── 1. It parses ────────────────────────────────────────────────────────────

let spec
try {
  spec = yaml.load(readFileSync(specPath, 'utf8'))
} catch (error) {
  console.error(`docs/api/openapi.yaml is not valid YAML.\n\n${error.message}`)
  process.exit(1)
}

// ── 1b. Every internal $ref resolves ────────────────────────────────────────

const unresolved = new Set()

const walk = (node, path) => {
  if (Array.isArray(node)) {
    node.forEach((value, index) => walk(value, `${path}/${index}`))
    return
  }

  if (node === null || typeof node !== 'object') {
    return
  }

  for (const [key, value] of Object.entries(node)) {
    if (key === '$ref' && typeof value === 'string' && value.startsWith('#/')) {
      // JSON pointer escaping: ~1 is a literal slash, ~0 a literal tilde.
      const segments = value
        .slice(2)
        .split('/')
        .map((segment) => segment.replace(/~1/g, '/').replace(/~0/g, '~'))

      let cursor = spec
      for (const segment of segments) {
        if (cursor !== null && typeof cursor === 'object' && segment in cursor) {
          cursor = cursor[segment]
        } else {
          unresolved.add(`${value}  (referenced at ${path})`)
          break
        }
      }
    } else {
      walk(value, `${path}/${key}`)
    }
  }
}

walk(spec, '')

if (unresolved.size > 0) {
  problems.push(`Unresolved $ref:\n  ${[...unresolved].join('\n  ')}`)
}

// ── 2. Operation ids and tags ───────────────────────────────────────────────

const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options']

const operations = []
for (const [path, item] of Object.entries(spec.paths ?? {})) {
  for (const [method, operation] of Object.entries(item)) {
    if (METHODS.includes(method)) {
      operations.push({ path, method, operation })
    }
  }
}

const ids = operations.map(({ operation }) => operation.operationId).filter(Boolean)
const duplicateIds = [...new Set(ids.filter((id, index) => ids.indexOf(id) !== index))]

if (duplicateIds.length > 0) {
  problems.push(`Duplicate operationId: ${duplicateIds.join(', ')}`)
}

const missingIds = operations
  .filter(({ operation }) => !operation.operationId)
  .map(({ method, path }) => `${method.toUpperCase()} ${path}`)

if (missingIds.length > 0) {
  problems.push(`Operations with no operationId:\n  ${missingIds.join('\n  ')}`)
}

const declaredTags = new Set((spec.tags ?? []).map((tag) => tag.name))
const undeclaredTags = [
  ...new Set(operations.flatMap(({ operation }) => operation.tags ?? []).filter((tag) => !declaredTags.has(tag))),
]

if (undeclaredTags.length > 0) {
  problems.push(`Tags used but not declared: ${undeclaredTags.join(', ')}`)
}

// ── 3. It matches the routes ────────────────────────────────────────────────

/** Parameter names differ between the two — `{company}` and `{companyId}` are the same endpoint. */
const normalise = (path) =>
  path
    .replace(/\{[^}]+\}/g, '{}')
    .replace(/\/+/g, '/')
    .replace(/\/$/, '')

let routes = null
try {
  routes = JSON.parse(execFileSync('php', ['artisan', 'route:list', '--json'], { cwd: root, encoding: 'utf8' }))
} catch {
  const message = 'Could not run `php artisan route:list` — the route coverage check did not run.'
  if (requireRoutes) {
    problems.push(message)
  } else {
    notes.push(message)
  }
}

if (routes !== null) {
  const documented = new Set(
    operations
      .filter(({ method }) => method !== 'head' && method !== 'options')
      .map(({ method, path }) => `${method.toUpperCase()} ${normalise(path)}`),
  )

  const served = new Set(
    routes
      .filter((route) => route.uri.startsWith('api/v1/'))
      .flatMap((route) =>
        route.method
          .split('|')
          .filter((method) => METHODS.includes(method.toLowerCase()) && method !== 'HEAD' && method !== 'OPTIONS')
          .map((method) => `${method} ${normalise(route.uri.replace(/^api\/v1/, ''))}`),
      ),
  )

  const undocumented = [...served].filter((route) => !documented.has(route)).sort()
  const phantom = [...documented].filter((route) => !served.has(route)).sort()

  if (undocumented.length > 0) {
    problems.push(`Served but not documented:\n  ${undocumented.join('\n  ')}`)
  }

  if (phantom.length > 0) {
    problems.push(`Documented but not served:\n  ${phantom.join('\n  ')}`)
  }

  notes.push(`${served.size} routes, ${documented.size} documented operations.`)
}

// ── Report ──────────────────────────────────────────────────────────────────

for (const note of notes) {
  console.log(note)
}

if (problems.length > 0) {
  console.error(`\ndocs/api/openapi.yaml has ${problems.length} problem(s):\n`)
  console.error(problems.join('\n\n'))
  process.exit(1)
}

console.log('docs/api/openapi.yaml is valid and matches the routes.')
