import { OAuthResourceServer, insufficientScope } from '@cloudflare/workers-oauth-provider';
import { McpServer } from '@modelcontextprotocol/server';
import { createMcpHandler, getMcpAuthContext } from 'agents/mcp/server';
import { env as bindings } from 'cloudflare:workers';
import { z } from 'zod';
import { AccessDenied, authorizeSiteRead, READ_ACTIONS } from './site-policy.mjs';
import { loadAuthorizedSites } from './registry.mjs';

const READ_SCOPE = 'sites:read';

function requestActor(context) {
  // These fields are populated only by a validated OAuth resource-server token.
  const scopes = context?.http?.authInfo?.scopes;
  const props = getMcpAuthContext()?.props;
  if (!Array.isArray(scopes) || !scopes.includes(READ_SCOPE) ||
      !props || typeof props !== 'object') {
    throw new AccessDenied('Missing authenticated site-read identity');
  }
  return {
    verified: true,
    tenantId: props.tenantId,
    subject: props.userId,
    scopes,
  };
}

function toolResponse(payload) {
  return { content: [{type: 'text', text: JSON.stringify(payload)}] };
}

function toolFailure() {
  // Do not disclose WordPress identifiers or other tenants on failures.
  return { isError: true, content: [{type: 'text', text: 'This site is unavailable or access was denied.'}] };
}

function createServer() {
  const server = new McpServer({name: 'Webactueel Central WordPress', version: '0.1.0'});

  server.registerTool('wordpress_sites_list', {
    description: 'List the WordPress websites explicitly approved for the signed-in user. No access is granted by installation alone.',
    inputSchema: {},
  }, async (_args, context) => {
    try {
      const sites = await loadAuthorizedSites(bindings.REGISTRY, requestActor(context));
      return toolResponse({sites});
    } catch {
      return toolFailure();
    }
  });

  server.registerTool('wordpress_site_read_plan', {
    description: 'Prepare a read-only inspection plan for a site already in your approved list. This does not contact or modify WordPress.',
    inputSchema: {
      site_id: z.string().min(3).max(80),
      action: z.enum(READ_ACTIONS),
    },
  }, async ({site_id, action}, context) => {
    try {
      const actor = requestActor(context);
      const sites = await loadAuthorizedSites(bindings.REGISTRY, actor);
      const chosen = sites.find(site => site.site_id === site_id);
      if (!chosen) throw new AccessDenied();
      // Re-check the site on every call. Future dispatch must perform
      // the same check again with the current registry and WordPress rights.
      const selected = [{
        id: chosen.site_id,
        tenantId: actor.tenantId,
        origin: chosen.site_url,
        label: chosen.label,
        status: 'active',
        originVerified: true,
        grants: {[actor.subject]: {read: true}},
      }];
      const plan = authorizeSiteRead(actor, selected, site_id, action);
      return toolResponse({plan, executed: false});
    } catch {
      return toolFailure();
    }
  });

  return server;
}

const handler = createMcpHandler(createServer, {
  route: '/mcp',
  corsOptions: false,
  legacy: 'reject',
});

export default new OAuthResourceServer({
  resourceMetadata: {
    resource: bindings.MCP_SERVER_URL,
    authorization_servers: [bindings.AUTH_SERVER_URL],
  },
  requiredScopes: [READ_SCOPE],
  validateToken: (env) => env.AUTH_SERVER.validateToken,
  handler: {
    fetch(request, env, context) {
      if (!context.auth?.scope?.includes(READ_SCOPE)) {
        return insufficientScope(context.auth, [READ_SCOPE]);
      }
      return handler(request, env, context);
    },
  },
});
