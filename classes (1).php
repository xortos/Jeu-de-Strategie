<?php
class Vaisseau {
    private $position;
    private $vie;
    private $energie;
    private $puissanceTir;
    private $drones = [];
    private $nom;
    private $joueurId;
    private $bouclierActif = false;
    private $resistanceBonus = 0;

    public function __construct($nom, $joueurId, $position) {
        $this->nom = $nom;
        $this->joueurId = $joueurId;
        $this->position = $position;
        $this->vie = 100;
        $this->energie = 100;
        $this->puissanceTir = 10;
    }

    public function deplacer($nouvellePosition, $coutEnergie = 5) {
        if ($this->energie >= $coutEnergie) {
            $this->position = $nouvellePosition;
            $this->energie -= $coutEnergie;
            return true;
        }
        return false;
    }

    public function tirer($cible, $coutEnergie = 20) {
        if ($this->energie >= $coutEnergie) {
            $this->energie -= $coutEnergie;
            return $this->puissanceTir;
        }
        return 0;
    }

    public function tirerLourd($cible) {
        $coutEnergie = 50;
        if ($this->energie >= $coutEnergie) {
            $this->energie -= $coutEnergie;
            return 35;
        }
        return 0;
    }

    // --- MODIFICATION DES COÛTS ICI ---
    public function lancerDrone($type, $position) {
        $cout = 0;
        switch($type) {
            case 'attaque': $cout = 15; break;        // Demandé : 15
            case 'defense': $cout = 10; break;        // Demandé : 10
            case 'reconnaissance': $cout = 5; break;  // Demandé : 5
            case 'controle': $cout = 25; break;       // Demandé : 25
        }

        if ($this->energie >= $cout && count($this->drones) < 3) {
            $this->energie -= $cout;
            $drone = new Drone($type, $position, $this->joueurId);
            $this->drones[] = $drone;
            return $drone;
        }
        return null;
    }

    public function recevoirDegats($degats) {
        $degatsReduits = $degats;
        if ($this->bouclierActif) {
            $degatsReduits = max(1, $degats - $this->resistanceBonus);
        }
        $this->vie -= $degatsReduits;
        if ($this->vie < 0) $this->vie = 0;
    }

    public function activerBouclier($resistance) {
        $this->bouclierActif = true;
        $this->resistanceBonus = $resistance;
    }

    public function desactiverBouclier() {
        $this->bouclierActif = false;
        $this->resistanceBonus = 0;
    }

    public function regenererEnergie($quantite = 3) {
        $this->energie += $quantite;
        if ($this->energie > 100) $this->energie = 100;
    }

    public function getPosition() { return $this->position; }
    public function getVie() { return $this->vie; }
    public function getEnergie() { return $this->energie; }
    public function getDrones() { return $this->drones; }
    public function getNom() { return $this->nom; }
    public function getJoueurId() { return $this->joueurId; }
    public function getBouclierActif() { return $this->bouclierActif; }
    public function getResistanceBonus() { return $this->resistanceBonus; }
    public function setPosition($pos) { $this->position = $pos; }
    public function setEnergie($energie) { $this->energie = $energie; }
    public function setVie($vie) { $this->vie = $vie; }
}

class Drone {
    private $type;
    private $position;
    private $energie;
    private $portee;
    private $joueurId;
    private $casesDecouvertes = [];
    private $toursRestants = 0;
    private $bouclierActif = false;

    public function __construct($type, $position, $joueurId) {
        $this->type = $type;
        $this->position = $position;
        $this->joueurId = $joueurId;
        $this->energie = 50;
        
        switch($type) {
            case 'attaque':
                $this->portee = 6;
                $this->toursRestants = 5;
                break;
            case 'defense':
                $this->portee = 4;
                $this->toursRestants = 5;
                break;
            case 'reconnaissance':
                $this->portee = 8;
                $this->toursRestants = 5;
                break;
            case 'controle':
                $this->portee = 2;
                break;
        }
    }

    public function agir($gameState) {
        switch($this->type) {
            case 'attaque': return $this->agirAttaque($gameState);
            case 'defense': return $this->agirDefense($gameState);
            case 'reconnaissance': return $this->agirReconnaissance($gameState);
            case 'controle': return $this->agirControle($gameState);
        }
        return null;
    }

    private function agirAttaque($gameState) {
        if ($this->energie < 10) return null;
        $cibles = $this->trouverCibles($gameState);
        if (!empty($cibles)) {
            $this->energie -= 10;
            return ['type' => 'attaque', 'cible' => $cibles[0], 'degats' => 3];
        }
        $this->deplacerVersCible($gameState);
        return null;
    }

    private function agirDefense($gameState) {
        if ($this->energie < 5) return null;
        $this->energie -= 5;
        $this->bouclierActif = true;
        return ['type' => 'defense', 'position' => $this->position, 'portee' => 3, 'reduction' => 3];
    }

    private function agirReconnaissance($gameState) {
        if ($this->toursRestants <= 0) return ['type' => 'expire'];
        if ($this->energie < 3) return null;
        $this->energie -= 3;
        
        $directions = [[0,1],[1,0],[0,-1],[-1,0]];
        $dir = $directions[array_rand($directions)];
        $nouvellePosition = [
            max(0, min(29, $this->position[0] + $dir[0])),
            max(0, min(24, $this->position[1] + $dir[1]))
        ];
        
        if (!$this->caseOccupee($gameState, $nouvellePosition)) {
            $this->position = $nouvellePosition;
        }
        
        if (!in_array($this->position, $this->casesDecouvertes)) {
            $this->casesDecouvertes[] = $this->position;
        }
        
        return ['type' => 'reconnaissance', 'cases' => $this->casesDecouvertes, 'nouvellePosition' => $this->position, 'toursRestants' => $this->toursRestants];
    }

    private function agirControle($gameState) {
        if ($this->energie < 8) return null;
        $this->energie -= 8;
        foreach ($gameState['tours'] as $index => $tour) {
            if ($tour['position'][0] == $this->position[0] && $tour['position'][1] == $this->position[1]) {
                return ['type' => 'controle', 'tourIndex' => $index, 'joueur' => $this->joueurId];
            }
        }
        return null;
    }

    private function trouverCibles($gameState) {
        $cibles = [];
        foreach ($gameState['vaisseaux'] as $joueurId => $vaisseau) {
            if ($joueurId != $this->joueurId) {
                $distance = abs($vaisseau['position'][0] - $this->position[0]) + abs($vaisseau['position'][1] - $this->position[1]);
                if ($distance <= $this->portee) {
                    $cibles[] = ['type' => 'vaisseau', 'joueur' => $joueurId, 'position' => $vaisseau['position']];
                }
            }
        }
        foreach ($gameState['vaisseaux'] as $joueurId => $vaisseau) {
            if ($joueurId != $this->joueurId && isset($vaisseau['drones'])) {
                foreach ($vaisseau['drones'] as $droneIndex => $drone) {
                    $distance = abs($drone['position'][0] - $this->position[0]) + abs($drone['position'][1] - $this->position[1]);
                    if ($distance <= $this->portee) {
                        $cibles[] = ['type' => 'drone', 'joueur' => $joueurId, 'droneIndex' => $droneIndex, 'position' => $drone['position']];
                    }
                }
            }
        }
        return $cibles;
    }

    private function deplacerVersCible($gameState) {
        $vaisseauxEnnemis = [];
        foreach ($gameState['vaisseaux'] as $joueurId => $vaisseau) {
            if ($joueurId != $this->joueurId) $vaisseauxEnnemis[] = $vaisseau['position'];
        }
        if (!empty($vaisseauxEnnemis)) {
            $cible = $vaisseauxEnnemis[0];
            $directions = [];
            if ($cible[0] > $this->position[0]) $directions[] = [1,0];
            elseif ($cible[0] < $this->position[0]) $directions[] = [-1,0];
            if ($cible[1] > $this->position[1]) $directions[] = [0,1];
            elseif ($cible[1] < $this->position[1]) $directions[] = [0,-1];
            
            if (!empty($directions)) {
                $dir = $directions[array_rand($directions)];
                $nouvellePosition = [
                    max(0, min(29, $this->position[0] + $dir[0])),
                    max(0, min(24, $this->position[1] + $dir[1]))
                ];
                if (!$this->caseOccupee($gameState, $nouvellePosition)) $this->position = $nouvellePosition;
            }
        }
    }

    private function caseOccupee($gameState, $position) {
        if (isset($gameState['obstacles'])) {
            foreach ($gameState['obstacles'] as $obstacle) {
                if ($obstacle[0] == $position[0] && $obstacle[1] == $position[1]) return true;
            }
        }
        foreach ($gameState['vaisseaux'] as $vaisseau) {
            if ($vaisseau['position'][0] == $position[0] && $vaisseau['position'][1] == $position[1]) return true;
        }
        if (isset($gameState['tours'])) {
            foreach ($gameState['tours'] as $tour) {
                if ($tour['position'][0] == $position[0] && $tour['position'][1] == $position[1]) return true;
            }
        }
        if (isset($gameState['vaisseaux'])) {
            foreach ($gameState['vaisseaux'] as $v) {
                if (isset($v['drones'])) {
                    foreach ($v['drones'] as $d) {
                        if ($d['position'][0] == $position[0] && $d['position'][1] == $position[1]) return true;
                    }
                }
            }
        }
        return false;
    }

    public function decrementerTours() {
        if ($this->type !== 'controle') {
            $this->toursRestants--;
            return $this->toursRestants <= 0;
        }
        return false;
    }

    public function getType() { return $this->type; }
    public function getPosition() { return $this->position; }
    public function getEnergie() { return $this->energie; }
    public function getPortee() { return $this->portee; }
    public function getJoueurId() { return $this->joueurId; }
    public function getCasesDecouvertes() { return $this->casesDecouvertes; }
    public function getToursRestants() { return $this->toursRestants; }
    public function getBouclierActif() { return $this->bouclierActif; }
    public function setEnergie($energie) { $this->energie = $energie; }
    public function setPosition($position) { $this->position = $position; }
    public function setBouclierActif($bouclierActif) { $this->bouclierActif = $bouclierActif; }
    public function setToursRestants($tours) { $this->toursRestants = $tours; }
}
?>