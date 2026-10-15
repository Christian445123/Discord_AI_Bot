// pm2-Konfiguration:  pm2 start ecosystem.config.js
const fs = require('fs');
const path = require('path');

// PANEL_PORT aus der .env lesen (Standard 8080)
let panelPort = process.env.PANEL_PORT || '8080';
try {
    const env = fs.readFileSync(path.join(__dirname, '.env'), 'utf8');
    const m = env.match(/^PANEL_PORT=['"]?(\d+)['"]?\s*$/m);
    if (m) panelPort = m[1];
} catch (e) { /* .env noch nicht vorhanden */ }

module.exports = {
    apps: [
        {
            name: 'discord-ai-bot',
            script: 'bot.php',
            interpreter: 'php',
            cwd: __dirname,
            autorestart: true,
            restart_delay: 5000,
            max_restarts: 1000,
            time: true,
        },
        {
            name: 'discord-ai-panel',
            script: 'php',
            args: `-S 0.0.0.0:${panelPort} -t panel`,
            interpreter: 'none',
            cwd: __dirname,
            autorestart: true,
            restart_delay: 3000,
            time: true,
        },
    ],
};
