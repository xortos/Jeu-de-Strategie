function createGame() {
  const name = document.getElementById('playerName').value;
  fetch('game.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `action=create&name=${encodeURIComponent(name)}`
  })
  .then(res => res.json())
  .then(data => {
    document.getElementById('status').innerText = `Partie créée. ID: ${data.gameId}`;
    // Rediriger vers la page de jeu
    setTimeout(() => {
      window.location.href = `game.html?gameId=${data.gameId}&playerName=${encodeURIComponent(name)}`;
    }, 1000);
  });
}

function joinGame() {
  const name = document.getElementById('playerName').value;
  const gameId = document.getElementById('gameId').value;
  fetch('game.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `action=join&name=${encodeURIComponent(name)}&gameId=${encodeURIComponent(gameId)}`
  })
  .then(res => res.json())
  .then(data => {
    document.getElementById('status').innerText = data.message;
    if (data.message === 'Rejoint avec succès') {
      // Rediriger vers la page de jeu
      setTimeout(() => {
        window.location.href = `game.html?gameId=${gameId}&playerName=${encodeURIComponent(name)}`;
      }, 1000);
    }
  });
}
