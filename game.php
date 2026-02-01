<?php
session_start();
include 'classes.php';

$file = 'games.json';
$data = json_decode(file_get_contents($file), true) ?? [];

$action = $_POST['action'] ?? '';
$name = $_POST['name'] ?? '';
$gameId = $_POST['gameId'] ?? '';

if ($action === 'create') {
  $id = uniqid();
  $data[$id] = [
    'players' => [$name],
    'state' => 'waiting',
    'gameState' => null
  ];
  file_put_contents($file, json_encode($data));
  echo json_encode(['gameId' => $id]);
  exit;
}

if ($action === 'join') {
  if (!isset($data[$gameId])) {
    echo json_encode(['message' => 'Partie introuvable']);
    exit;
  }
  if (count($data[$gameId]['players']) >= 2) {
    echo json_encode(['message' => 'Partie complète']);
    exit;
  }
  $data[$gameId]['players'][] = $name;
  
  if (count($data[$gameId]['players']) == 2) {
    $data[$gameId]['state'] = 'playing';
    $data[$gameId]['gameState'] = initialiserJeu($data[$gameId]['players']);
  }
  
  file_put_contents($file, json_encode($data));
  echo json_encode(['message' => 'Rejoint avec succès', 'state' => $data[$gameId]['state']]);
  exit;
}

if ($action === 'getGameState') {
  if (!isset($data[$gameId])) {
    echo json_encode(['error' => 'Partie introuvable']);
    exit;
  }
  
  $playerIndex = array_search($name, $data[$gameId]['players']);
  if ($playerIndex === false) {
    echo json_encode(['error' => 'Joueur non trouvé']);
    exit;
  }
  
  echo json_encode([
    'gameState' => $data[$gameId]['gameState'],
    'playerId' => $playerIndex,
    'state' => $data[$gameId]['state'],
    'players' => $data[$gameId]['players']
  ]);
  exit;
}

if ($action === 'playTurn') {
  if (!isset($data[$gameId])) {
    echo json_encode(['error' => 'Partie introuvable']);
    exit;
  }
  
  $playerIndex = array_search($name, $data[$gameId]['players']);
  if ($playerIndex === false) {
    echo json_encode(['error' => 'Joueur non trouvé']);
    exit;
  }
  
  $move = $_POST['move'] ?? '';
  $result = traiterTour($data[$gameId]['gameState'], $playerIndex, $move);
  
  if ($result) {
    $data[$gameId]['gameState'] = $result;
    file_put_contents($file, json_encode($data));
    echo json_encode(['success' => true, 'gameState' => $result]);
  } else {
    echo json_encode(['error' => 'Action invalide']);
  }
  exit;
}

function initialiserJeu($players) {
  $grille = array_fill(0, 30, array_fill(0, 25, null));
  
  $vaisseauJ0 = new Vaisseau($players[0] . "'s Ship", 0, [2, 2]);
  $vaisseauJ1 = new Vaisseau($players[1] . "'s Ship", 1, [27, 22]);
  
  $gameState = [
    'grille' => $grille,
    'vaisseaux' => [
      0 => [
        'nom' => $vaisseauJ0->getNom(),
        'joueurId' => $vaisseauJ0->getJoueurId(),
        'position' => $vaisseauJ0->getPosition(),
        'vie' => $vaisseauJ0->getVie(),
        'energie' => $vaisseauJ0->getEnergie(),
        'puissanceTir' => 10,
        'drones' => [],
        'bouclierActif' => $vaisseauJ0->getBouclierActif(),
        'resistanceBonus' => $vaisseauJ0->getResistanceBonus(),
        'bonus' => []
      ],
      1 => [
        'nom' => $vaisseauJ1->getNom(),
        'joueurId' => $vaisseauJ1->getJoueurId(),
        'position' => $vaisseauJ1->getPosition(),
        'vie' => $vaisseauJ1->getVie(),
        'energie' => $vaisseauJ1->getEnergie(),
        'puissanceTir' => 10,
        'drones' => [],
        'bouclierActif' => $vaisseauJ1->getBouclierActif(),
        'resistanceBonus' => $vaisseauJ1->getResistanceBonus(),
        'bonus' => []
      ]
    ],
    'tours' => [],
    'obstacles' => [],
    'zonesControlees' => [0 => 0, 1 => 0],
    'tour' => 1,
    'joueurActif' => 0,
    'status' => 'playing',
    'casesDecouvertes' => [],
    'evenements' => [],
    'asteroides_imminents' => [] // NOUVEAU: Stockage des alertes
  ];
  
  $positionsTours = [];
  $bonusTours = [
    ['type' => 'deplacement', 'nom' => 'Vitesse', 'valeur' => 2],
    ['type' => 'attaque', 'nom' => 'Puissance', 'valeur' => 1],
    ['type' => 'defense', 'nom' => 'Résistance', 'valeur' => 0.2],
    ['type' => 'vision', 'nom' => 'Vision', 'valeur' => 2]
  ];
  
  for ($i = 0; $i < 4; $i++) {
    do {
      $pos = [rand(5, 24), rand(5, 19)];
      $tropProche = false;
      foreach ($positionsTours as $posExistante) {
        if (abs($pos[0] - $posExistante[0]) < 6 && abs($pos[1] - $posExistante[1]) < 6) {
          $tropProche = true;
          break;
        }
      }
    } while ($tropProche || 
             (abs($pos[0] - 2) < 8 && abs($pos[1] - 2) < 8) || 
             (abs($pos[0] - 27) < 8 && abs($pos[1] - 22) < 8));
    
    $positionsTours[] = $pos;
    $gameState['tours'][] = [
      'position' => $pos,
      'controleur' => null,
      'toursControle' => 0,
      'bonus' => $bonusTours[$i]
    ];
  }
  
  $obstacles = [];
  $nbObstacles = floor(30 * 25 * 0.08);
  for ($i = 0; $i < $nbObstacles; $i++) {
    do {
      $pos = [rand(0, 29), rand(0, 24)];
      $tropProche = false;
      
      if ((abs($pos[0] - 2) < 3 && abs($pos[1] - 2) < 3) || 
          (abs($pos[0] - 27) < 3 && abs($pos[1] - 22) < 3)) {
        $tropProche = true;
        continue;
      }
      
      foreach ($positionsTours as $tourPos) {
        if ($pos[0] == $tourPos[0] && $pos[1] == $tourPos[1]) {
          $tropProche = true;
          break;
        }
      }
      
      foreach ($obstacles as $obstacle) {
        if (abs($pos[0] - $obstacle[0]) < 2 && abs($pos[1] - $obstacle[1]) < 2) {
          $tropProche = true;
          break;
        }
      }
      
    } while ($tropProche || in_array($pos, $obstacles));
    
    $obstacles[] = $pos;
  }
  $gameState['obstacles'] = $obstacles;
  
  $gameState['grille'][2][2] = ['type' => 'vaisseau', 'joueur' => 0];
  $gameState['grille'][27][22] = ['type' => 'vaisseau', 'joueur' => 1];
  
  foreach ($gameState['tours'] as $index => $tour) {
    $gameState['grille'][$tour['position'][0]][$tour['position'][1]] = 
      ['type' => 'tour', 'index' => $index, 'bonus' => $tour['bonus']];
  }
  
  foreach ($gameState['obstacles'] as $obstacle) {
    $gameState['grille'][$obstacle[0]][$obstacle[1]] = 
      ['type' => 'obstacle'];
  }
  
  return $gameState;
}

function tirer($gameState, $playerIndex, $cible, $estLourd = false) {
  $vaisseau = &$gameState['vaisseaux'][$playerIndex];
  $vaisseauObj = new Vaisseau($vaisseau['nom'], $vaisseau['joueurId'], $vaisseau['position']);
  $vaisseauObj->setVie($vaisseau['vie']);
  $vaisseauObj->setEnergie($vaisseau['energie']);
  
  $portee = $estLourd ? 6 : 8;
  
  $distance = abs($cible[0] - $vaisseau['position'][0]) + abs($cible[1] - $vaisseau['position'][1]);
  if ($distance > $portee) {
    return false;
  }
  
  $degatsBase = 0;
  if ($estLourd) {
      $degatsBase = $vaisseauObj->tirerLourd($cible);
  } else {
      $degatsBase = $vaisseauObj->tirer($cible);
  }
  
  if ($degatsBase > 0) {
    $vaisseau['energie'] = $vaisseauObj->getEnergie();
    
    $puissanceTir = $degatsBase;
    if (isset($vaisseau['bonus']['attaque']) && $vaisseau['bonus']['attaque']) {
        $puissanceTir += ($estLourd ? 5 : 0); 
    }
    
    $cibleContenu = $gameState['grille'][$cible[0]][$cible[1]];
    if ($cibleContenu) {
      if ($cibleContenu['type'] === 'vaisseau' && $cibleContenu['joueur'] != $playerIndex) {
        $adversaireIndex = $cibleContenu['joueur'];
        $degats = $puissanceTir;
        
        $estProtege = false;
        foreach ($gameState['vaisseaux'] as $v) {
          foreach ($v['drones'] as $drone) {
            if ($drone['type'] === 'defense' && $drone['bouclierActif']) {
              $distanceBouclier = abs($cible[0] - $drone['position'][0]) + 
                                 abs($cible[1] - $drone['position'][1]);
              if ($distanceBouclier <= 3) {
                $estProtege = true;
                $degats = max(1, $degats - 3);
                break 2;
              }
            }
          }
        }
        
        if (isset($gameState['vaisseaux'][$adversaireIndex]['resistanceBonus'])) {
          $degats = ceil($degats * (1 - $gameState['vaisseaux'][$adversaireIndex]['resistanceBonus']));
        }
        
        $gameState['vaisseaux'][$adversaireIndex]['vie'] -= $degats;
        
        $gameState['evenements'][] = [
          'type' => 'degats',
          'joueur' => $adversaireIndex,
          'degats' => $degats,
          'protège' => $estProtege,
          'source' => $estLourd ? 'canon_plasma' : 'tir_standard'
        ];
        
        if ($gameState['vaisseaux'][$adversaireIndex]['vie'] <= 0) {
          $gameState['status'] = 'finished';
          $gameState['gagnant'] = $playerIndex;
        }
      }
      elseif ($cibleContenu['type'] === 'drone') {
        $droneJoueur = $cibleContenu['joueur'];
        $droneIndex = $cibleContenu['droneIndex'];
        
        if (isset($gameState['vaisseaux'][$droneJoueur]['drones'][$droneIndex])) {
          $drone = $gameState['vaisseaux'][$droneJoueur]['drones'][$droneIndex];
          $dronePosition = $drone['position'];
          $droneType = $drone['type'];
          
          if ($droneType === 'controle') {
            foreach ($gameState['tours'] as $index => &$tour) {
              if ($tour['position'][0] == $dronePosition[0] && $tour['position'][1] == $dronePosition[1]) {
                $ancienControleur = $tour['controleur'];
                if ($ancienControleur !== null) {
                    retirerBonusTour($gameState, $ancienControleur, $index);
                }
                $tour['controleur'] = null;
                $tour['toursControle'] = 0;
                
                $gameState['grille'][$dronePosition[0]][$dronePosition[1]] = [
                    'type' => 'tour', 
                    'index' => $index,
                    'bonus' => $tour['bonus']
                ];
                
                $gameState['evenements'][] = [
                  'type' => 'tour_liberee',
                  'tourPosition' => $tour['position'],
                  'joueur' => $playerIndex,
                  'ancienJoueur' => $ancienControleur
                ];
                break;
              }
            }
          } else {
            $gameState['grille'][$dronePosition[0]][$dronePosition[1]] = null;
          }
          
          unset($gameState['vaisseaux'][$droneJoueur]['drones'][$droneIndex]);
          $gameState['vaisseaux'][$droneJoueur]['drones'] = array_values($gameState['vaisseaux'][$droneJoueur]['drones']);
          
          foreach ($gameState['vaisseaux'][$droneJoueur]['drones'] as $newIndex => $d) {
             $pos = $d['position'];
             if (isset($gameState['grille'][$pos[0]][$pos[1]]) && $gameState['grille'][$pos[0]][$pos[1]]['type'] === 'drone') {
                 $gameState['grille'][$pos[0]][$pos[1]]['droneIndex'] = $newIndex;
             }
          }
          
          $gameState['evenements'][] = [
            'type' => 'drone_detruit',
            'joueur' => $droneJoueur,
            'droneType' => $droneType
          ];
        }
      }
    }
    
    $degatsDrones = 0;
    foreach ($gameState['vaisseaux'][$playerIndex]['drones'] as $drone) {
      if ($drone['type'] === 'attaque') {
        $distanceDroneCible = abs($cible[0] - $drone['position'][0]) + abs($cible[1] - $drone['position'][1]);
        if ($distanceDroneCible <= $drone['portee']) {
          $degatsDrones += 3;
        }
      }
    }
    
    if ($degatsDrones > 0 && $cibleContenu && $cibleContenu['type'] === 'vaisseau' && $cibleContenu['joueur'] != $playerIndex) {
      $adversaireIndex = $cibleContenu['joueur'];
      $gameState['vaisseaux'][$adversaireIndex]['vie'] -= $degatsDrones;
      
      $gameState['evenements'][] = [
        'type' => 'degats_drone',
        'joueur' => $adversaireIndex,
        'degats' => $degatsDrones,
        'source' => 'drone_attaque'
      ];
      
      if ($gameState['vaisseaux'][$adversaireIndex]['vie'] <= 0) {
        $gameState['status'] = 'finished';
        $gameState['gagnant'] = $playerIndex;
      }
    }
    
    return passerTour($gameState);
  }
  
  return false;
}

function traiterTour($gameState, $playerIndex, $move) {
  if ($gameState['joueurActif'] != $playerIndex) {
    return false;
  }
  
  $moveData = json_decode($move, true);
  
  switch($moveData['action']) {
    case 'deplacer':
      return deplacerVaisseau($gameState, $playerIndex, $moveData['position']);
    case 'tirer':
      return tirer($gameState, $playerIndex, $moveData['cible'], false);
    case 'tirerLourd':
      return tirer($gameState, $playerIndex, $moveData['cible'], true);
    case 'lancerDrone':
      return lancerDrone($gameState, $playerIndex, $moveData['type'], $moveData['position']);
    case 'passer':
      return passerTour($gameState);
  }
  
  return false;
}

function retirerBonusTour(&$gameState, $joueurId, $tourIndex) {
    $tour = $gameState['tours'][$tourIndex];
    $bonus = $tour['bonus'];
    
    switch($bonus['type']) {
        case 'deplacement':
            $gameState['vaisseaux'][$joueurId]['bonus']['deplacement'] = false;
            break;
        case 'attaque':
            $gameState['vaisseaux'][$joueurId]['bonus']['attaque'] = false;
            $gameState['vaisseaux'][$joueurId]['puissanceTir'] -= $bonus['valeur'];
            break;
        case 'defense':
            $gameState['vaisseaux'][$joueurId]['bonus']['defense'] = false;
            $gameState['vaisseaux'][$joueurId]['resistanceBonus'] -= $bonus['valeur'];
            break;
        case 'vision':
            $gameState['vaisseaux'][$joueurId]['bonus']['vision'] = 0;
            break;
    }
    
    $gameState['evenements'][] = [
        'type' => 'bonus_perdu',
        'joueur' => $joueurId,
        'bonus' => $bonus['nom']
    ];
}

function deplacerVaisseau($gameState, $playerIndex, $nouvellePosition) {
  $vaisseau = &$gameState['vaisseaux'][$playerIndex];
  $vaisseauObj = new Vaisseau($vaisseau['nom'], $vaisseau['joueurId'], $vaisseau['position']);
  $vaisseauObj->setVie($vaisseau['vie']);
  $vaisseauObj->setEnergie($vaisseau['energie']);
  
  $anciennePos = $vaisseau['position'];
  
  $distanceMax = 1;
  if (isset($vaisseau['bonus']['deplacement']) && $vaisseau['bonus']['deplacement']) {
    $distanceMax = 2;
  }
  
  $distance = abs($nouvellePosition[0] - $anciennePos[0]) + abs($nouvellePosition[1] - $anciennePos[1]);
  if ($distance > $distanceMax) {
    return false;
  }
  
  foreach ($gameState['obstacles'] as $obstacle) {
    if ($obstacle[0] == $nouvellePosition[0] && $obstacle[1] == $nouvellePosition[1]) {
      return false;
    }
  }
  
  foreach ($gameState['vaisseaux'] as $id => $autreVaisseau) {
    if ($id != $playerIndex && 
        $autreVaisseau['position'][0] == $nouvellePosition[0] && 
        $autreVaisseau['position'][1] == $nouvellePosition[1]) {
      return false;
    }
  }
  
  if ($vaisseauObj->deplacer($nouvellePosition)) {
    $gameState['grille'][$anciennePos[0]][$anciennePos[1]] = null;
    $gameState['grille'][$nouvellePosition[0]][$nouvellePosition[1]] = 
      ['type' => 'vaisseau', 'joueur' => $playerIndex];
    
    $vaisseau['position'] = $vaisseauObj->getPosition();
    $vaisseau['energie'] = $vaisseauObj->getEnergie();
    
    return passerTour($gameState);
  }
  
  return false;
}

function lancerDrone($gameState, $playerIndex, $type, $position) {
  $vaisseau = &$gameState['vaisseaux'][$playerIndex];
  $vaisseauObj = new Vaisseau($vaisseau['nom'], $vaisseau['joueurId'], $vaisseau['position']);
  $vaisseauObj->setVie($vaisseau['vie']);
  $vaisseauObj->setEnergie($vaisseau['energie']);
  
  foreach ($gameState['obstacles'] as $obstacle) {
    if ($obstacle[0] == $position[0] && $obstacle[1] == $position[1]) {
      return false;
    }
  }
  
  foreach ($gameState['vaisseaux'] as $id => $autreVaisseau) {
    if ($autreVaisseau['position'][0] == $position[0] && $autreVaisseau['position'][1] == $position[1]) {
      return false;
    }
  }
  
  if ($type === 'controle') {
    $estSurTour = false;
    $tourIndex = null;
    foreach ($gameState['tours'] as $index => $tour) {
      if ($tour['position'][0] == $position[0] && $tour['position'][1] == $position[1]) {
        $estSurTour = true;
        $tourIndex = $index;
        break;
      }
    }
    if (!$estSurTour) {
      return false;
    }
    
    if ($gameState['tours'][$tourIndex]['controleur'] !== null) {
      return false;
    }
    
    $contenu = $gameState['grille'][$position[0]][$position[1]];
    if ($contenu && $contenu['type'] === 'drone') {
      return false;
    }
  } else {
    $contenu = $gameState['grille'][$position[0]][$position[1]];
    if ($contenu && ($contenu['type'] === 'vaisseau' || $contenu['type'] === 'tour' || $contenu['type'] === 'drone')) {
      return false;
    }
  }
  
  if (count($vaisseau['drones']) < 3) {
    $droneObj = $vaisseauObj->lancerDrone($type, $position);
    if ($droneObj) {
      $vaisseau['energie'] = $vaisseauObj->getEnergie();
      
      $droneIndex = count($vaisseau['drones']);
      
      if ($type === 'controle') {
        $gameState['grille'][$position[0]][$position[1]] = [
          'type' => 'drone', 
          'joueur' => $playerIndex, 
          'droneType' => $type, 
          'droneIndex' => $droneIndex
        ];
      } else {
        $gameState['grille'][$position[0]][$position[1]] = [
          'type' => 'drone', 
          'joueur' => $playerIndex, 
          'droneType' => $type, 
          'droneIndex' => $droneIndex
        ];
      }
      
      $vaisseau['drones'][] = [
        'type' => $type,
        'position' => $position,
        'energie' => $droneObj->getEnergie(),
        'portee' => $droneObj->getPortee(),
        'casesDecouvertes' => [],
        'toursRestants' => $droneObj->getToursRestants(),
        'bouclierActif' => false
      ];
      
      if ($type === 'controle') {
        foreach ($gameState['tours'] as $index => &$tour) {
          if ($tour['position'][0] == $position[0] && $tour['position'][1] == $position[1]) {
            $ancienControleur = $tour['controleur'];
            $tour['controleur'] = $playerIndex;
            $tour['toursControle'] = 1;
            
            if ($ancienControleur !== null && $ancienControleur !== $playerIndex) {
                retirerBonusTour($gameState, $ancienControleur, $index);
            }
            
            appliquerBonusTour($gameState, $playerIndex, $index);
            
            $gameState['zonesControlees'][$playerIndex] = 0;
            foreach ($gameState['tours'] as $t) {
              if ($t['controleur'] === $playerIndex) {
                $gameState['zonesControlees'][$playerIndex]++;
              }
            }
            
            $messageEvenement = '';
            if ($ancienControleur === null) {
                $messageEvenement = "🎯 {$gameState['vaisseaux'][$playerIndex]['nom']} prend le contrôle d'une tour !";
            } else {
                $messageEvenement = "⚔️ {$gameState['vaisseaux'][$playerIndex]['nom']} reprend une tour à {$gameState['vaisseaux'][$ancienControleur]['nom']} !";
            }
            
            $gameState['evenements'][] = [
              'type' => 'tour_controlee',
              'joueur' => $playerIndex,
              'ancienJoueur' => $ancienControleur,
              'zonesControlees' => $gameState['zonesControlees'][$playerIndex],
              'bonus' => $tour['bonus']['nom'],
              'message' => $messageEvenement
            ];
            
            if ($gameState['zonesControlees'][$playerIndex] >= 3) {
              $gameState['status'] = 'finished';
              $gameState['gagnant'] = $playerIndex;
            }
            break;
          }
        }
      }
      
      return passerTour($gameState);
    }
  }
  
  return false;
}

function appliquerBonusTour(&$gameState, $joueurId, $tourIndex) {
    $tour = $gameState['tours'][$tourIndex];
    $bonus = $tour['bonus'];
    
    $dejaApplique = false;
    switch($bonus['type']) {
        case 'deplacement':
            if (!$gameState['vaisseaux'][$joueurId]['bonus']['deplacement']) {
                $gameState['vaisseaux'][$joueurId]['bonus']['deplacement'] = true;
            } else {
                $dejaApplique = true;
            }
            break;
        case 'attaque':
            if (!$gameState['vaisseaux'][$joueurId]['bonus']['attaque']) {
                $gameState['vaisseaux'][$joueurId]['bonus']['attaque'] = true;
                $gameState['vaisseaux'][$joueurId]['puissanceTir'] += $bonus['valeur'];
            } else {
                $dejaApplique = true;
            }
            break;
        case 'defense':
            if (!$gameState['vaisseaux'][$joueurId]['bonus']['defense']) {
                $gameState['vaisseaux'][$joueurId]['bonus']['defense'] = true;
                $gameState['vaisseaux'][$joueurId]['resistanceBonus'] += $bonus['valeur'];
            } else {
                $dejaApplique = true;
            }
            break;
        case 'vision':
            if ($gameState['vaisseaux'][$joueurId]['bonus']['vision'] == 0) {
                $gameState['vaisseaux'][$joueurId]['bonus']['vision'] = $bonus['valeur'];
            } else {
                $dejaApplique = true;
            }
            break;
    }
    
    if (!$dejaApplique) {
        $gameState['evenements'][] = [
            'type' => 'bonus_obtenu',
            'joueur' => $joueurId,
            'bonus' => $bonus['nom']
        ];
    }
}

function passerTour($gameState) {
  executerActionsDrones($gameState);
  gererAsteroides($gameState);
  mettreAJourControleTours($gameState);
  verifierVictoire($gameState);
  
  foreach ($gameState['vaisseaux'] as &$vaisseau) {
    $vaisseauObj = new Vaisseau($vaisseau['nom'], $vaisseau['joueurId'], $vaisseau['position']);
    $vaisseauObj->setVie($vaisseau['vie']);
    $vaisseauObj->setEnergie($vaisseau['energie']);
    
    $vaisseauObj->regenererEnergie(3);
    
    $vaisseau['energie'] = $vaisseauObj->getEnergie();
    
    if ($vaisseau['energie'] < 20) {
      $gameState['evenements'][] = [
        'type' => 'energie_faible',
        'joueur' => $vaisseau['joueurId']
      ];
    }
  }
  
  $gameState['tour']++;
  $gameState['joueurActif'] = 1 - $gameState['joueurActif'];
  
  return $gameState;
}

function executerActionsDrones(&$gameState) {
  foreach ($gameState['vaisseaux'] as $joueurId => &$vaisseau) {
    for ($droneIndex = count($vaisseau['drones']) - 1; $droneIndex >= 0; $droneIndex--) {
      $drone = &$vaisseau['drones'][$droneIndex];
      
      if ($drone['type'] !== 'controle') {
        $drone['toursRestants']--;
        
        if ($drone['toursRestants'] <= 0) {
          $pos = $drone['position'];
          $gameState['grille'][$pos[0]][$pos[1]] = null;
          array_splice($vaisseau['drones'], $droneIndex, 1);
          
          $gameState['evenements'][] = [
            'type' => 'drone_expire',
            'joueur' => $joueurId,
            'droneType' => $drone['type']
          ];
          continue;
        }
      }
      
      $droneObj = new Drone($drone['type'], $drone['position'], $joueurId);
      $droneObj->setEnergie($drone['energie']);
      $droneObj->setPosition($drone['position']);
      $droneObj->setBouclierActif($drone['bouclierActif']);
      if ($drone['type'] !== 'controle') {
        $droneObj->setToursRestants($drone['toursRestants']);
      }
      
      $action = $droneObj->agir($gameState);
      
      if ($drone['type'] === 'defense' && $action && $action['type'] === 'defense') {
        $drone['bouclierActif'] = true;
      }
      
      if ($action && $action['type'] === 'reconnaissance') {
        $anciennePos = $drone['position'];
        $nouvellePos = $action['nouvellePosition'];
        
        if ($anciennePos[0] != $nouvellePos[0] || $anciennePos[1] != $nouvellePos[1]) {
          $gameState['grille'][$anciennePos[0]][$anciennePos[1]] = null;
          $gameState['grille'][$nouvellePos[0]][$nouvellePos[1]] = [
            'type' => 'drone',
            'joueur' => $joueurId,
            'droneType' => 'reconnaissance',
            'droneIndex' => $droneIndex
          ];
        }
        
        if (isset($action['cases'])) {
          foreach ($action['cases'] as $case) {
            if (!in_array($case, $gameState['casesDecouvertes'])) {
              $gameState['casesDecouvertes'][] = $case;
            }
          }
        }
      }
      
      $drone['energie'] = $droneObj->getEnergie();
      $drone['position'] = $droneObj->getPosition();
      $drone['bouclierActif'] = $droneObj->getBouclierActif();
      if ($drone['type'] !== 'controle') {
        $drone['toursRestants'] = $droneObj->getToursRestants();
      }
    }
  }
}

// --- NOUVEAU SYSTÈME ASTÉROÏDES TÉLÉGRAPHIÉS ---
function gererAsteroides(&$gameState) {
  $tour = $gameState['tour'];

  // 1. TRAITEMENT DES IMPACTS (Ceux prévus pour CE tour)
  if (!isset($gameState['asteroides_imminents'])) {
      $gameState['asteroides_imminents'] = [];
  }

  $nouveauxImminents = [];

  foreach ($gameState['asteroides_imminents'] as $menace) {
      if ($menace['tourImpact'] == $tour) {
          $centre = $menace['position'];
          $degatsMax = $menace['degats'];
          
          // Zone d'impact (Centre + Croix)
          $zones = [
              ['pos' => $centre, 'ratio' => 1.0], // Centre : 100% dégâts
              ['pos' => [$centre[0]+1, $centre[1]], 'ratio' => 0.5], // Adjacents : 50%
              ['pos' => [$centre[0]-1, $centre[1]], 'ratio' => 0.5],
              ['pos' => [$centre[0], $centre[1]+1], 'ratio' => 0.5],
              ['pos' => [$centre[0], $centre[1]-1], 'ratio' => 0.5]
          ];

          foreach ($zones as $zone) {
              $x = $zone['pos'][0];
              $y = $zone['pos'][1];
              
              if ($x < 0 || $x > 29 || $y < 0 || $y > 24) continue;

              $degatsReels = floor($degatsMax * $zone['ratio']);

              // 1. Vérifier Vaisseaux
              foreach ($gameState['vaisseaux'] as &$vaisseau) {
                  if ($vaisseau['position'][0] == $x && $vaisseau['position'][1] == $y) {
                      
                      $estProtege = false;
                      foreach ($gameState['vaisseaux'] as $v) {
                          foreach ($v['drones'] as $drone) {
                              if ($drone['type'] === 'defense' && isset($drone['bouclierActif']) && $drone['bouclierActif']) {
                                  $dist = abs($vaisseau['position'][0] - $drone['position'][0]) + 
                                          abs($vaisseau['position'][1] - $drone['position'][1]);
                                  if ($dist <= 3) {
                                      $estProtege = true;
                                      $degatsReels = max(1, $degatsReels - 5);
                                      break 2;
                                  }
                              }
                          }
                      }

                      if (isset($vaisseau['resistanceBonus'])) {
                          $degatsReels = ceil($degatsReels * (1 - $vaisseau['resistanceBonus']));
                      }

                      $vaisseau['vie'] -= $degatsReels;
                      
                      $gameState['evenements'][] = [
                          'type' => 'degats',
                          'joueur' => $vaisseau['joueurId'],
                          'degats' => $degatsReels,
                          'protège' => $estProtege,
                          'source' => 'asteroide'
                      ];
                  }
              }

              // 2. Vérifier Drones (Destruction simplifiée)
              if (isset($gameState['grille'][$x][$y]) && 
                  isset($gameState['grille'][$x][$y]['type']) && 
                  $gameState['grille'][$x][$y]['type'] === 'drone') {
                  
                  $joueurDrone = $gameState['grille'][$x][$y]['joueur'];
                  $indexDrone = $gameState['grille'][$x][$y]['droneIndex'];
                  
                  if (isset($gameState['vaisseaux'][$joueurDrone]['drones'][$indexDrone])) {
                       unset($gameState['vaisseaux'][$joueurDrone]['drones'][$indexDrone]);
                       $gameState['vaisseaux'][$joueurDrone]['drones'] = array_values($gameState['vaisseaux'][$joueurDrone]['drones']);
                       $gameState['grille'][$x][$y] = null;
                       
                       $gameState['evenements'][] = [
                           'type' => 'drone_detruit',
                           'joueur' => $joueurDrone,
                           'droneType' => 'asteroide_impact'
                       ];
                  }
              }
          }

          $gameState['evenements'][] = [
              'type' => 'asteroide_impact',
              'position' => $centre,
              'degats' => $degatsMax
          ];
      } else {
          $nouveauxImminents[] = $menace;
      }
  }
  
  $gameState['asteroides_imminents'] = $nouveauxImminents;

  // 2. GÉNÉRATION DE NOUVELLES MENACES (Pour le futur)
  $nbAsteroides = 0;
  $chance = min(60, 10 + ($tour * 2));

  if (rand(1, 100) <= $chance) {
      $nbAsteroides = 1;
      if ($tour > 10 && rand(1, 100) < 40) $nbAsteroides = 2;
      if ($tour > 20 && rand(1, 100) < 20) $nbAsteroides = 3;
  }

  for ($i = 0; $i < $nbAsteroides; $i++) {
      $position = [rand(2, 27), rand(2, 22)]; 
      $degats = rand(25, 35) + floor($tour / 2); 
      
      $gameState['asteroides_imminents'][] = [
          'position' => $position,
          'tourImpact' => $tour + 1, // Frappe au tour suivant
          'degats' => $degats
      ];
      
      $gameState['evenements'][] = [
          'type' => 'alerte_asteroide',
          'position' => $position
      ];
  }
}

function mettreAJourControleTours(&$gameState) {
  $gameState['zonesControlees'] = [0 => 0, 1 => 0];
  
  foreach ($gameState['tours'] as $tour) {
    if ($tour['controleur'] !== null) {
      $gameState['zonesControlees'][$tour['controleur']]++;
    }
  }
}

function verifierVictoire(&$gameState) {
  foreach ($gameState['vaisseaux'] as $joueurId => $vaisseau) {
    if ($vaisseau['vie'] <= 0) {
      $gameState['status'] = 'finished';
      $gameState['gagnant'] = 1 - $joueurId;
      return;
    }
  }
  
  if ($gameState['zonesControlees'][0] >= 3) {
    $gameState['status'] = 'finished';
    $gameState['gagnant'] = 0;
    return;
  }
  if ($gameState['zonesControlees'][1] >= 3) {
    $gameState['status'] = 'finished';
    $gameState['gagnant'] = 1;
    return;
  }
}
?>
