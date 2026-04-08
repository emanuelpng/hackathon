#!/usr/bin/env node
/**
 * Onfly OAuth helper — run this standalone to get fresh tokens.
 * Usage: node get_tokens.js
 * Then open the printed URL in your browser and log in.
 * Tokens are saved to ~/.onfly-tokens.json AND written to Laravel .env.
 */
const express = require('express');
const axios   = require('axios');
const fs      = require('fs');
const path    = require('path');
const net     = require('net');

const TOKEN_FILE = path.join(process.env.HOME, '.onfly-tokens.json');
const ENV_FILE   = path.resolve(__dirname, '../app/.env');

const CONFIG = {
  apiUrl:       'https://api.onfly.com',
  appUrl:       'https://app.onfly.com',
  gatewayUrl:   'https://toguro-app-prod.onfly.com',
  clientId:     process.env.CLIENT_ID     || '1212',
  clientSecret: process.env.CLIENT_SECRET || 'fLWgKiTE4qmkx7pXwfEcTB7yNjKiisygEbbinWEV',
  port:         parseInt(process.env.PORT) || 3333,
  state:        'onfly_' + Math.random().toString(36).substring(2, 12),
};

function decodeJwt(token) {
  try { return JSON.parse(Buffer.from(token.split('.')[1], 'base64').toString()); }
  catch (e) { return null; }
}

async function isPortFree(port) {
  return new Promise(resolve => {
    const s = net.createServer();
    s.once('error', () => resolve(false));
    s.once('listening', () => s.close(() => resolve(true)));
    s.listen(port);
  });
}

/**
 * Update a key=value line in the .env file.
 * If the key doesn't exist, append it.
 */
function updateEnv(filePath, key, value) {
  try {
    let content = fs.existsSync(filePath) ? fs.readFileSync(filePath, 'utf8') : '';
    const regex = new RegExp(`^${key}=.*$`, 'm');
    const line  = `${key}=${value}`;
    if (regex.test(content)) {
      content = content.replace(regex, line);
    } else {
      content += `\n${line}`;
    }
    fs.writeFileSync(filePath, content, 'utf8');
  } catch (e) {
    console.warn(`Warning: could not update .env: ${e.message}`);
  }
}

(async () => {
  const port = (await isPortFree(CONFIG.port)) ? CONFIG.port : CONFIG.port + 1;
  const redirectUri = `http://localhost:${port}/callback`;

  const authorizeUrl = `${CONFIG.appUrl}/v2#/auth/oauth/authorize`
    + `?client_id=${encodeURIComponent(CONFIG.clientId)}`
    + `&redirect_uri=${encodeURIComponent(redirectUri)}`
    + `&response_type=code`
    + `&state=${encodeURIComponent(CONFIG.state)}`;

  const app = express();

  app.get('/callback', async (req, res) => {
    const { code, state, error } = req.query;

    if (error) {
      res.send(`<h1>Error: ${error}</h1><p>${req.query.error_description || ''}</p>`);
      console.error('OAuth denied:', error);
      process.exit(1);
    }

    if (state !== CONFIG.state) {
      res.send('<h1>State mismatch</h1>');
      console.error('State mismatch — possible CSRF.');
      process.exit(1);
    }

    try {
      // 1. Exchange code → V3 access + refresh tokens
      const tokenResp = await axios.post(`${CONFIG.apiUrl}/oauth/token`, {
        grant_type:    'authorization_code',
        code,
        client_id:     CONFIG.clientId,
        client_secret: CONFIG.clientSecret,
        redirect_uri:  redirectUri,
      }, { headers: { 'Content-Type': 'application/json', Accept: 'application/json' } });

      const access_token  = tokenResp.data.access_token;
      const refresh_token = tokenResp.data.refresh_token || null;
      const expires_in    = tokenResp.data.expires_in   || 900;

      // 2. Immediately exchange V3 token → gateway token (must be within ~15 min of issuance)
      let gateway_token         = null;
      let gateway_refresh_token = null;
      try {
        const gwResp = await axios.get(`${CONFIG.apiUrl}/auth/token/internal`, {
          headers: { Authorization: `Bearer ${access_token}`, Accept: 'application/prs.onfly.v1+json' }
        });
        gateway_token         = gwResp.data.token;
        gateway_refresh_token = gwResp.data.refreshToken || null;
        console.log('Gateway token obtained.');
      } catch (e) {
        console.warn('Could not get gateway token:', e.response?.data || e.message);
      }

      // 3. Save to ~/.onfly-tokens.json
      const stored = {
        access_token,
        refresh_token,
        expires_at:           Date.now() + expires_in * 1000,
        gateway_token,
        gateway_refresh_token,
        gateway_expires_at:   Date.now() + 15 * 60 * 1000,
        user:                 null,
      };
      fs.writeFileSync(TOKEN_FILE, JSON.stringify(stored, null, 2), 'utf8');

      // 4. Update Laravel .env
      updateEnv(ENV_FILE, 'ONFLY_API_TOKEN',              access_token);
      updateEnv(ENV_FILE, 'ONFLY_REFRESH_TOKEN',          refresh_token || '');
      if (gateway_refresh_token) {
        updateEnv(ENV_FILE, 'ONFLY_GATEWAY_REFRESH_TOKEN', gateway_refresh_token);
      }

      console.log('\n✅  Autenticado com sucesso!\n');
      console.log(`access_token          : ${access_token.substring(0, 40)}...`);
      console.log(`refresh_token         : ${refresh_token ? refresh_token.substring(0, 40) + '...' : 'N/A'}`);
      console.log(`gateway_token         : ${gateway_token ? gateway_token.substring(0, 40) + '...' : 'N/A'}`);
      console.log(`gateway_refresh_token : ${gateway_refresh_token ? gateway_refresh_token.substring(0, 40) + '...' : 'N/A'}`);
      console.log(`\nTokens saved → ${TOKEN_FILE}`);
      console.log(`Laravel .env updated → ${ENV_FILE}`);

      res.send('<h1>✅ Autenticado!</h1><p>Pode fechar esta aba e voltar ao terminal.</p>');
      setTimeout(() => process.exit(0), 500);
    } catch (e) {
      const errData = e.response?.data || { message: e.message };
      res.send(`<h1>Error</h1><pre>${JSON.stringify(errData, null, 2)}</pre>`);
      console.error('Token exchange failed:', errData);
      process.exit(1);
    }
  });

  app.listen(port, () => {
    console.log('\n🔐  Onfly OAuth — abra a URL abaixo no seu browser:\n');
    console.log(`   ${authorizeUrl}`);
    console.log(`\nAguardando callback em http://localhost:${port}/callback ...\n`);
  });
})();
