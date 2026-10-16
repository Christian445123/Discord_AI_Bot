// pm2-Konfiguration:  pm2 start ecosystem.config.js
// Es gibt nur EINEN Prozess: der Bot startet das Web-Panel selbst als Kindprozess (siehe src/PanelServer.php).
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
            kill_timeout: 5000,
            time: true,
        },
    ],
};
