class JeuSpatial {
    constructor() {
        this.gameId = null;
        this.playerName = null;
        this.playerId = null;
        this.gameState = null;
        this.selectedCase = null;
        this.selectedAction = null;
        this.droneType = null;
        this.players = [];
        this.asteroidesAnimes = new Set();
        
        // NOUVEAU : Compteur pour ne pas réafficher les anciens événements
        this.lastEventCount = 0; 
        
        this.init();
    }
    
    init() {
        const urlParams = new URLSearchParams(window.location.search);
        this.gameId = urlParams.get('gameId');
        this.playerName = urlParams.get('playerName');
        
        if (!this.gameId || !this.playerName) {
            alert('Paramètres de jeu manquants');
            window.location.href = 'index.html';
            return;
        }
        
        this.setupEventListeners();
        this.afficherCodePartie();
        this.chargerEtatJeu();
        
        setInterval(() => this.chargerEtatJeu(), 1500);
    }

    // --- NOUVELLE MÉTHODE : Gestion du Log ---
    ajouterLog(message, type = 'info') {
        const logContainer = document.getElementById('gameStatus');
        
        // Création de la ligne
        const ligne = document.createElement('div');
        ligne.className = `log-line ${type}`;
        
        // Ajout de l'heure
        const now = new Date();
        const time = `${now.getHours().toString().padStart(2,'0')}:${now.getMinutes().toString().padStart(2,'0')}:${now.getSeconds().toString().padStart(2,'0')}`;
        ligne.innerHTML = `<span style="opacity:0.5; font-size:0.8em">[${time}]</span> ${message}`;
        
        logContainer.appendChild(ligne);
        
        // Auto-scroll vers le bas
        logContainer.scrollTop = logContainer.scrollHeight;
    }
    
    afficherCodePartie() {
        const codeDisplay = document.getElementById('gameCodeDisplay');
        codeDisplay.textContent = this.gameId;
        
        document.getElementById('copyCodeBtn').addEventListener('click', () => {
            this.copierCodePartie();
        });
    }
    
    copierCodePartie() {
        navigator.clipboard.writeText(this.gameId).then(() => {
            const btn = document.getElementById('copyCodeBtn');
            btn.textContent = '✓';
            setTimeout(() => {
                btn.textContent = '📋';
            }, 2000);
        }).catch(err => {
            alert('Impossible de copier le code. Copiez-le manuellement: ' + this.gameId);
        });
    }
    
    chargerEtatJeu() {
        fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=getGameState&name=${encodeURIComponent(this.playerName)}&gameId=${encodeURIComponent(this.gameId)}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                console.error(data.error);
                return;
            }
            
            this.gameState = data.gameState;
            this.playerId = data.playerId;
            this.players = data.players || [];
            
            this.nettoyerDronesExpires();
            
            this.afficherGrille();
            this.mettreAJourInterface();
            this.gererAffichageCodePartie();
        })
        .catch(error => {
            console.error('Erreur de chargement:', error);
        });
    }
    
    nettoyerDronesExpires() {
        if (!this.gameState || !this.gameState.vaisseaux) return;
        
        Object.values(this.gameState.vaisseaux).forEach((vaisseau, joueurId) => {
            for (let i = vaisseau.drones.length - 1; i >= 0; i--) {
                const drone = vaisseau.drones[i];
                if (drone.type === 'reconnaissance' && drone.toursRestants <= 0) {
                    const [x, y] = drone.position;
                    this.gameState.grille[x][y] = null;
                    vaisseau.drones.splice(i, 1);
                }
            }
        });
    }
    
    gererAffichageCodePartie() {
        const codeContainer = document.getElementById('gameCodeContainer');
        
        // Si la partie est finie OU si la partie a commencé (2 joueurs présents)
        if ((this.gameState && this.gameState.status === 'finished') || (this.players && this.players.length >= 2)) {
            codeContainer.style.display = 'none';
        } else {
            codeContainer.style.display = 'block';
        }
    }
    
    afficherGrille() {
        const grilleElement = document.getElementById('grille');
        grilleElement.innerHTML = '';
        
        if (!this.gameState || !this.gameState.grille) {
            for (let y = 0; y < 25; y++) {
                for (let x = 0; x < 30; x++) {
                    const caseElement = document.createElement('div');
                    caseElement.className = 'case';
                    caseElement.style.width = '25px';
                    caseElement.style.height = '25px';
                    grilleElement.appendChild(caseElement);
                }
            }
            return;
        }
        
        for (let y = 0; y < 25; y++) {
            for (let x = 0; x < 30; x++) {
                const caseElement = document.createElement('div');
                caseElement.className = 'case';
                caseElement.dataset.x = x;
                caseElement.dataset.y = y;
                caseElement.style.width = '25px';
                caseElement.style.height = '25px';
                
                // --- VISUALISATION DES ZONES DE DANGER ---
                if (this.gameState.asteroides_imminents) {
                    this.gameState.asteroides_imminents.forEach(menace => {
                        if (menace.position[0] === x && menace.position[1] === y) {
                            caseElement.classList.add('danger-zone');
                            caseElement.title = `⚠️ IMPACT IMMINENT ! Dégâts massifs prévus au tour ${menace.tourImpact}`;
                        }
                    });
                }
                
                const contenu = this.gameState.grille[x][y];
                let texte = '';
                let title = caseElement.title || '';
                
                const estVisible = this.estCaseVisible(x, y);
                const estDecouverte = this.estCaseDecouverte(x, y);
                
                if (contenu && (estVisible || estDecouverte)) {
                    if (contenu.type === 'vaisseau') {
                        caseElement.classList.add(`vaisseau-j${contenu.joueur}`);
                        texte = 'V';
                        title = `Vaisseau ${this.players[contenu.joueur]}`;
                    } else if (contenu.type === 'drone') {
                        caseElement.classList.add(`drone-${contenu.droneType}`);
                        texte = contenu.droneType === 'attaque' ? 'A' : 
                               contenu.droneType === 'defense' ? 'D' :
                               contenu.droneType === 'reconnaissance' ? 'R' : 'C';
                        title = `Drone ${contenu.droneType} - ${this.players[contenu.joueur]}`;
                        
                        if (contenu.droneType !== 'controle') {
                            const droneData = this.gameState.vaisseaux[contenu.joueur].drones[contenu.droneIndex];
                            if (droneData && droneData.toursRestants !== undefined) {
                                title += ` (${droneData.toursRestants} tours restants)`;
                            }
                        }
                        
                        const estSurTour = this.estSurTour(x, y);
                        if (estSurTour) {
                            caseElement.classList.add('sur-tour');
                        }
                    } else if (contenu.type === 'tour') {
                        caseElement.classList.add('tour');
                        
                        if (contenu.bonus) {
                            switch(contenu.bonus.type) {
                                case 'deplacement':
                                    caseElement.classList.add('tour-vitesse');
                                    texte = '⚡';
                                    break;
                                case 'attaque':
                                    caseElement.classList.add('tour-puissance');
                                    texte = '💥';
                                    break;
                                case 'defense':
                                    caseElement.classList.add('tour-defense');
                                    texte = '🛡️';
                                    break;
                                case 'vision':
                                    caseElement.classList.add('tour-vision');
                                    texte = '👁️';
                                    break;
                            }
                            title = `Tour ${contenu.bonus.nom} - ${contenu.bonus.type}`;
                        } else {
                            texte = 'T';
                            title = 'Tour de contrôle';
                        }
                        
                        if (this.gameState.tours && this.gameState.tours[contenu.index]) {
                            const tour = this.gameState.tours[contenu.index];
                            if (tour.controleur !== null) {
                                caseElement.classList.add(`controle-j${tour.controleur}`);
                                title += ` - Contrôlée par ${this.players[tour.controleur]}`;
                                
                                if (tour.toursControle === 1) {
                                    caseElement.classList.add('tour-nouvelle-controle');
                                }
                            }
                        }
                    } else if (contenu.type === 'obstacle') {
                        caseElement.classList.add('obstacle');
                        title = 'Obstacle';
                    }
                }
                
                if (estVisible) {
                    caseElement.classList.add('visible');
                }
                
                if (estDecouverte && !estVisible) {
                    caseElement.classList.add('decouverte');
                }
                
                if (this.selectedAction === 'deplacer' && this.estDeplacementPossible(x, y)) {
                    caseElement.classList.add('deplacement-possible');
                }
                
                caseElement.textContent = texte;
                caseElement.title = title;
                
                caseElement.addEventListener('click', () => this.selectionnerCase(x, y));
                grilleElement.appendChild(caseElement);
            }
        }
        
        this.ajouterAnimationsAsteroides();
    }
    
    estCaseVisible(x, y) {
        if (!this.gameState || !this.gameState.vaisseaux || !this.gameState.vaisseaux[this.playerId]) {
            return false;
        }
        
        const vaisseau = this.gameState.vaisseaux[this.playerId];
        const posVaisseau = vaisseau.position;
        
        let visionBase = 4;
        
        if (vaisseau.bonus && vaisseau.bonus.vision) {
            visionBase += vaisseau.bonus.vision;
        }
        
        const distanceVaisseau = Math.abs(x - posVaisseau[0]) + Math.abs(y - posVaisseau[1]);
        
        if (distanceVaisseau <= visionBase) {
            return true;
        }
        
        if (this.gameState.vaisseaux[this.playerId].drones) {
            for (const drone of this.gameState.vaisseaux[this.playerId].drones) {
                if (drone.type === 'reconnaissance') {
                    const distanceDrone = Math.abs(x - drone.position[0]) + Math.abs(y - drone.position[1]);
                    if (distanceDrone <= 6) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    estCaseDecouverte(x, y) {
        if (!this.gameState || !this.gameState.casesDecouvertes) {
            return false;
        }
        
        return this.gameState.casesDecouvertes.some(pos => 
            Array.isArray(pos) && pos[0] === x && pos[1] === y
        );
    }
    
    estDeplacementPossible(x, y) {
        if (!this.gameState || !this.gameState.vaisseaux || !this.gameState.vaisseaux[this.playerId]) {
            return false;
        }
        
        const vaisseau = this.gameState.vaisseaux[this.playerId];
        const posVaisseau = vaisseau.position;
        
        let distanceMax = 1;
        if (vaisseau.bonus && vaisseau.bonus.deplacement) {
            distanceMax = 2;
        }
        
        const distance = Math.abs(x - posVaisseau[0]) + Math.abs(y - posVaisseau[1]);
        if (distance > distanceMax) {
            return false;
        }
        
        if (this.gameState.grille[x][y] && this.gameState.grille[x][y].type === 'obstacle') {
            return false;
        }
        
        if (this.gameState.grille[x][y] && this.gameState.grille[x][y].type === 'vaisseau') {
            return false;
        }
        
        return true;
    }
    
    estSurTour(x, y) {
        if (!this.gameState || !this.gameState.tours) return false;
        
        return this.gameState.tours.some(tour => 
            tour.position[0] === x && tour.position[1] === y
        );
    }
    
    getTourAtPosition(x, y) {
        if (!this.gameState || !this.gameState.tours) return null;
        
        for (const tour of this.gameState.tours) {
            if (tour.position[0] === x && tour.position[1] === y) {
                return tour;
            }
        }
        return null;
    }
    
    ajouterAnimationsAsteroides() {
        if (this.gameState.evenements) {
            this.gameState.evenements.forEach((evenement, index) => {
                if (evenement.type === 'asteroide_impact') {
                    const uniqueKey = `impact_${index}_${evenement.position[0]}_${evenement.position[1]}`;
                    
                    if (!this.asteroidesAnimes.has(uniqueKey)) {
                        this.asteroidesAnimes.add(uniqueKey);
                        this.animerAsteroide(evenement.position);
                    }
                }
            });
        }
    }
    
    animerAsteroide(position) {
        const grilleContainer = document.getElementById('grille').parentElement;
        
        const asteroideElement = document.createElement('div');
        asteroideElement.className = 'asteroide-animation';
        asteroideElement.textContent = '☄️'; 
        asteroideElement.style.cssText = `
            position: absolute;
            left: ${position[0] * 25}px;
            top: ${position[1] * 25}px;
            width: 25px;
            height: 25px;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 100;
            text-shadow: 0 0 5px #ff5722;
        `;
        
        asteroideElement.style.left = (position[0] * 25) + 'px';
        asteroideElement.style.top = (position[1] * 25) + 'px';

        document.getElementById('grille').appendChild(asteroideElement);
        
        setTimeout(() => {
            asteroideElement.remove();
        }, 1500);
    }
    
    selectionnerCase(x, y) {
        // --- MODIF : Utilisation du Log au lieu du textContent direct ---
        
        if (this.selectedAction === 'lancerDrone' && !this.estCaseVisible(x, y)) {
            this.ajouterLog('Impossible : Zone hors de vue', 'error');
            return;
        }
        
        if (this.gameState && this.gameState.grille[x][y] && this.gameState.grille[x][y].type === 'obstacle') {
            this.ajouterLog('Erreur : Case occupée par un obstacle', 'error');
            return;
        }
        
        if (this.selectedAction === 'lancerDrone' && this.droneType === 'controle') {
            const estSurTour = this.estSurTour(x, y);
            if (!estSurTour) {
                this.ajouterLog('Le drone de contrôle doit être placé sur une tour', 'warning');
                return;
            }
            
            const tour = this.getTourAtPosition(x, y);
            if (tour && tour.controleur !== null) {
                this.ajouterLog('Cette tour est déjà contrôlée', 'error');
                return;
            }
            
            const contenu = this.gameState.grille[x][y];
            if (contenu && contenu.type === 'drone') {
                this.ajouterLog('Case déjà occupée par un drone', 'error');
                return;
            }
        } 
        else if (this.selectedAction === 'lancerDrone') {
            const contenu = this.gameState.grille[x][y];
            if (contenu && (contenu.type === 'vaisseau' || contenu.type === 'tour' || contenu.type === 'drone')) {
                this.ajouterLog('Case déjà occupée', 'error');
                return;
            }
        }
        
        if (this.selectedAction === 'deplacer' && !this.estDeplacementPossible(x, y)) {
            this.ajouterLog('Déplacement impossible (trop loin ou obstacle)', 'error');
            return;
        }
        
        this.selectedCase = [x, y];
        
        document.querySelectorAll('.case').forEach(c => c.classList.remove('selected'));
        const caseElement = document.querySelector(`.case[data-x="${x}"][data-y="${y}"]`);
        if (caseElement) {
            caseElement.classList.add('selected');
        }
        
        if (this.selectedAction) {
            this.executerAction();
        }
    }
    
    mettreAJourInterface() {
        if (!this.gameState) {
            // Ici on n'utilise pas le log pour le chargement initial
            return;
        }
        
        document.getElementById('turnNumber').textContent = this.gameState.tour || 1;
        
        if (this.gameState.vaisseaux && this.gameState.vaisseaux[0] && this.gameState.vaisseaux[1]) {
            const vaisseauJ1 = this.gameState.vaisseaux[0];
            const vaisseauJ2 = this.gameState.vaisseaux[1];
            
            this.mettreAJourJoueur(1, vaisseauJ1);
            this.mettreAJourJoueur(2, vaisseauJ2);
            
            document.getElementById('player1Name').textContent = this.players[0] || 'Joueur 1';
            document.getElementById('player2Name').textContent = this.players[1] || 'Joueur 2';
            
            const zonesJ1 = this.gameState.zonesControlees ? this.gameState.zonesControlees[0] : 0;
            const zonesJ2 = this.gameState.zonesControlees ? this.gameState.zonesControlees[1] : 0;
            
            this.mettreAJourZonesControlees(1, zonesJ1);
            this.mettreAJourZonesControlees(2, zonesJ2);
        }
        
        // --- MODIF : Gestion de l'indicateur de tour séparé du Log ---
        const turnIndicator = document.getElementById('turnIndicator');
        
        if (this.gameState.status === 'finished') {
            let message = '🎉 PARTIE TERMINÉE 🎉';
            if (this.gameState.gagnant !== undefined) {
                message += ` - Gagnant: ${this.players[this.gameState.gagnant]}`;
                const winnerCard = document.getElementById(`player${this.gameState.gagnant + 1}Info`);
                if (winnerCard) {
                    winnerCard.classList.add('victoire');
                }
            }
            if (turnIndicator) {
                turnIndicator.textContent = message;
                turnIndicator.style.color = '#4caf50';
                turnIndicator.style.borderColor = '#4caf50';
            }
        } else if (this.gameState.joueurActif === this.playerId) {
            if (turnIndicator) {
                turnIndicator.textContent = '🟢 À VOTRE TOUR - Choisissez une action';
                turnIndicator.style.color = '#4fc3f7';
                turnIndicator.style.borderColor = '#4fc3f7';
            }
        } else {
            if (turnIndicator) {
                turnIndicator.textContent = '🔴 TOUR ADVERSE...';
                turnIndicator.style.color = '#ff5252';
                turnIndicator.style.borderColor = '#ff5252';
            }
        }
        
        const estMonTour = this.gameState.joueurActif === this.playerId && this.gameState.status !== 'finished';
        document.getElementById('btnDeplacer').disabled = !estMonTour;
        document.getElementById('btnTirer').disabled = !estMonTour;
        document.getElementById('btnTirLourd').disabled = !estMonTour;
        document.getElementById('btnDroneAttaque').disabled = !estMonTour;
        document.getElementById('btnDroneDefense').disabled = !estMonTour;
        document.getElementById('btnDroneRecon').disabled = !estMonTour;
        document.getElementById('btnDroneControle').disabled = !estMonTour;
        document.getElementById('btnFinTour').disabled = !estMonTour;
        
        document.getElementById('player1Info').classList.toggle('active', this.gameState.joueurActif === 0);
        document.getElementById('player2Info').classList.toggle('active', this.gameState.joueurActif === 1);
        
        this.afficherEvenements();
    }
    
    mettreAJourJoueur(numero, vaisseau) {
        const vie = vaisseau.vie;
        const energie = vaisseau.energie;
        
        document.getElementById(`p${numero}Vie`).textContent = vie;
        document.getElementById(`p${numero}Energie`).textContent = energie;
        document.getElementById(`p${numero}VieBar`).style.width = `${vie}%`;
        document.getElementById(`p${numero}EnergieBar`).style.width = `${energie}%`;
        
        const vieBar = document.getElementById(`p${numero}VieBar`);
        if (vie <= 25) {
            vieBar.style.background = 'linear-gradient(90deg, #f44336, #d32f2f)';
        } else if (vie <= 50) {
            vieBar.style.background = 'linear-gradient(90deg, #ff9800, #f57c00)';
        } else {
            vieBar.style.background = 'linear-gradient(90deg, #4caf50, #45a049)';
        }
        
        const bonusElement = document.getElementById(`p${numero}Bonus`);
        if (bonusElement) {
            let bonusHTML = '';
            if (vaisseau.bonus && Object.keys(vaisseau.bonus).length > 0) {
                bonusHTML = '<div class="bonus-list">';
                if (vaisseau.bonus.deplacement) {
                    bonusHTML += '<span class="bonus-item">⚡ x2</span>';
                }
                if (vaisseau.bonus.attaque) {
                    bonusHTML += '<span class="bonus-item">💥 +1</span>';
                }
                if (vaisseau.bonus.defense) {
                    bonusHTML += '<span class="bonus-item">🛡️ 20%</span>';
                }
                if (vaisseau.bonus.vision) {
                    bonusHTML += '<span class="bonus-item">👁️ +2</span>';
                }
                bonusHTML += '</div>';
            }
            bonusElement.innerHTML = bonusHTML;
        }
    }
    
    mettreAJourZonesControlees(numero, zones) {
        const zonesElement = document.getElementById(`p${numero}Zones`);
        if (zonesElement) {
            zonesElement.textContent = `Zones: ${zones}/3`;
            
            if (zones >= 2) {
                zonesElement.style.color = '#f44336';
                zonesElement.style.background = 'rgba(244, 67, 54, 0.2)';
                zonesElement.style.fontWeight = 'bold';
            } else if (zones >= 1) {
                zonesElement.style.color = '#ff9800';
                zonesElement.style.background = 'rgba(255, 152, 0, 0.2)';
            } else {
                zonesElement.style.color = '#9b59b6';
                zonesElement.style.background = 'rgba(155, 89, 182, 0.1)';
            }
        }
    }
    
    // --- MODIF : Gestion complète de l'historique des événements ---
    afficherEvenements() {
        if (!this.gameState.evenements) return;

        // On boucle seulement sur les NOUVEAUX événements
        for (let i = this.lastEventCount; i < this.gameState.evenements.length; i++) {
            const ev = this.gameState.evenements[i];
            let message = '';
            let type = 'info';

            switch(ev.type) {
                case 'alerte_asteroide':
                    message = `⚠️ ALERTE : Impact d'astéroïde imminent en [${ev.position[0]},${ev.position[1]}] !`;
                    type = 'warning';
                    break;
                    
                case 'asteroide_impact':
                    message = `☄️ IMPACT CONFIRMÉ en [${ev.position[0]},${ev.position[1]}] !`;
                    type = 'error';
                    // L'animation est gérée séparément
                    break;

                case 'degats':
                    let icon = '💥';
                    let typeTxt = '';
                    if (ev.source === 'canon_plasma') {
                        icon = '⚛️';
                        typeTxt = ' par CANON PLASMA';
                    } else if (ev.source === 'drone') {
                        typeTxt = ' [Drone]';
                    } else if (ev.source === 'asteroide') {
                        icon = '☄️';
                        typeTxt = ' par ASTÉROÏDE';
                    }
                    
                    message = `${icon} ${this.players[ev.joueur]} subit ${ev.degats} dégâts${typeTxt}`;
                    if (ev.protège) {
                        message += ` (🛡️ bouclier actif)`;
                    }
                    type = 'combat';
                    break;

                case 'degats_drone':
                    message = `🤖 Drone d'attaque inflige ${ev.degats} dégâts à ${this.players[ev.joueur]}`;
                    type = 'combat';
                    break;
                    
                case 'energie_faible':
                    message = `⚠️ ${this.players[ev.joueur]} - Énergie critique !`;
                    type = 'warning';
                    break;
                    
                case 'drone_epuise':
                    message = `🔋 Drone ${ev.droneType} de ${this.players[ev.joueur]} à court d'énergie`;
                    type = 'info';
                    break;
                    
                case 'tour_controlee':
                    if (ev.message) {
                        message = ev.message;
                    } else if (ev.ancienJoueur !== null && ev.ancienJoueur !== undefined) {
                        message = `⚔️ ${this.players[ev.joueur]} reprend une tour à ${this.players[ev.ancienJoueur]} ! (${ev.zonesControlees}/3)`;
                    } else {
                        message = `🎯 ${this.players[ev.joueur]} prend le contrôle d'une tour ! (${ev.zonesControlees}/3)`;
                    }
                    if (ev.bonus && !message.includes(ev.bonus)) {
                        message += ` - Bonus: ${ev.bonus}`;
                    }
                    type = 'success';
                    break;

                case 'tour_liberee':
                    message = `🔄 Tour libérée à [${ev.tourPosition[0]},${ev.tourPosition[1]}]`;
                    type = 'info';
                    break;

                case 'drone_detruit':
                    if (ev.droneType === 'asteroide_impact') {
                         message = `💥 Drone de ${this.players[ev.joueur]} écrasé par un astéroïde !`;
                    } else {
                        message = `💥 Drone ${ev.droneType} de ${this.players[ev.joueur]} détruit`;
                    }
                    type = 'combat';
                    break;
                    
                case 'bonus_obtenu':
                    message = `✨ ${this.players[ev.joueur]} obtient le bonus: ${ev.bonus}`;
                    type = 'success';
                    break;
                    
                case 'drone_expire':
                    message = `⏰ Drone ${ev.droneType} de ${this.players[ev.joueur]} a expiré`;
                    type = 'info';
                    break;
            }
            
            if (message) {
                this.ajouterLog(message, type);
            }
        }
        
        // Mettre à jour le compteur
        this.lastEventCount = this.gameState.evenements.length;
    }
    
    setupEventListeners() {
        document.getElementById('btnDeplacer').addEventListener('click', () => {
            this.selectedAction = 'deplacer';
            this.ajouterLog('→ Sélectionnez une case adjacente pour vous déplacer', 'info');
            this.afficherGrille();
        });
        
        document.getElementById('btnTirer').addEventListener('click', () => {
            this.selectedAction = 'tirer';
            this.ajouterLog('🔫 Sélectionnez une cible (vaisseau ou drone adverse) pour tirer (portée: 8 cases)', 'info');
        });

        document.getElementById('btnTirLourd').addEventListener('click', () => {
            this.selectedAction = 'tirerLourd';
            this.ajouterLog('⚠️ CANON PLASMA : Sélectionnez une cible (Portée courte: 6 cases) - Coût: 50 Énergie', 'warning');
        });
        
        document.getElementById('btnDroneAttaque').addEventListener('click', () => {
            this.selectedAction = 'lancerDrone';
            this.droneType = 'attaque';
            this.ajouterLog('🚀 Sélectionnez une case VISIBLE pour lancer le drone d\'attaque', 'info');
        });
        
        document.getElementById('btnDroneDefense').addEventListener('click', () => {
            this.selectedAction = 'lancerDrone';
            this.droneType = 'defense';
            this.ajouterLog('🛡️ Sélectionnez une case VISIBLE pour lancer le drone de défense', 'info');
        });
        
        document.getElementById('btnDroneRecon').addEventListener('click', () => {
            this.selectedAction = 'lancerDrone';
            this.droneType = 'reconnaissance';
            this.ajouterLog('👁️ Sélectionnez une case VISIBLE pour lancer le drone de reconnaissance', 'info');
        });
        
        document.getElementById('btnDroneControle').addEventListener('click', () => {
            this.selectedAction = 'lancerDrone';
            this.droneType = 'controle';
            this.ajouterLog('🏳️ Sélectionnez une tour LIBRE et VISIBLE pour lancer le drone de contrôle', 'info');
        });
        
        document.getElementById('btnFinTour').addEventListener('click', () => {
            this.finTour();
            this.ajouterLog('⏳ Fin du tour envoyée...', 'info');
        });
    }
    
    executerAction() {
        if (!this.selectedCase || !this.selectedAction) return;
        
        let moveData = {};
        
        switch(this.selectedAction) {
            case 'deplacer':
                moveData = { action: 'deplacer', position: this.selectedCase };
                break;
            case 'tirer':
                moveData = { action: 'tirer', cible: this.selectedCase };
                break;
            case 'tirerLourd':
                moveData = { action: 'tirerLourd', cible: this.selectedCase };
                break;
            case 'lancerDrone':
                moveData = { 
                    action: 'lancerDrone', 
                    type: this.droneType, 
                    position: this.selectedCase 
                };
                break;
        }
        
        this.envoyerAction(moveData);
        
        this.selectedAction = null;
        this.selectedCase = null;
        this.droneType = null;
        document.querySelectorAll('.case').forEach(c => c.classList.remove('selected'));
    }
    
    envoyerAction(moveData) {
        fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=playTurn&name=${encodeURIComponent(this.playerName)}&gameId=${encodeURIComponent(this.gameId)}&move=${encodeURIComponent(JSON.stringify(moveData))}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                this.ajouterLog('❌ Erreur: ' + data.error, 'error');
            } else {
                this.gameState = data.gameState;
                this.afficherGrille();
                this.mettreAJourInterface();
                // Confirmation facultative
            }
        })
        .catch(error => {
            this.ajouterLog('❌ Erreur de communication serveur', 'error');
        });
    }
    
    finTour() {
        this.envoyerAction({action: 'passer'});
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new JeuSpatial();
});