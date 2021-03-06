<?php

namespace SmartSearch\SearchBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Contrôleur principal du bundle Search
 * */
class SearchController extends Controller
{
    /**
     * Action de la page d'acceuil
     * @return $this
     * */
    public function homeAction(Request $request)
    {
        $reviewRepository = $this->getDoctrine()->getRepository('SmartSearchSearchBundle:Review');
        $datesCrawl = $reviewRepository->findDistinctDate();
        $defaultDateCrawl = "2015-11-13";
        $keyword='';
		
		//var_dump($datesCrawl);die;
        $form = $this->createFormBuilder()
            ->add('keyword', 'text', array('required'=>false))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isValid()) {
            $keyword = $form->get('keyword')->getData();
            $keywordArray = explode(" ", $keyword);
            $listeKeywords = array();
            foreach($keywordArray as $motcle) {
                $listeKeywords[] = $this->cleanContent($motcle);
            }
            $keywordCleaned = implode("+", $listeKeywords);
            $dateCrawl = $_POST['selectedCrawlDate'];
            return $this->redirect($this->generateUrl('smart_search_keyword',
                                    array("keyword" => $keywordCleaned, "dateCrawl" => $dateCrawl)));
        }
        return $this->render('SmartSearchSearchBundle:Search:index.html.twig', array("form" => $form->createView(), "keyword" => $keyword, "dateCrawl" => $defaultDateCrawl, "datesCrawl" => $datesCrawl));
    }
    /**
     * Retourne la liste des résultats pour une expression-clé donnée
     * @return $this
     * */
    public function searchAction(Request $request, $keyword, $dateCrawl)
    {
        $reviewRepository = $this->getDoctrine()->getRepository('SmartSearchSearchBundle:Review');
        $datesCrawl = $reviewRepository->findDistinctDate();

        $form = $this->createFormBuilder()
            ->add('keyword', 'text', array('required'=>false))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isValid()) {

            $keyword = $form->get('keyword')->getData();
            $keywordArray = explode(" ", $keyword);
            $listeKeywords = array();
            foreach($keywordArray as $motcle) {
                $listeKeywords[] = $this->cleanContent($motcle);
            }

            $keywordCleaned = implode("+", $listeKeywords);
            $dateCrawl = $_POST['selectedCrawlDate'];
            return $this->redirect($this->generateUrl('smart_search_keyword',
                                array(
                                        "keyword" => $keywordCleaned,
                                        "dateCrawl" => $dateCrawl //Date de crawl provenant du formulaire
                                    )));
        }
        $keywordArray = explode("+", $keyword);
        //$results = $this->displayResults($keywordArray, $dateCrawl);
        //Condition pour les requêtes de type from:date to:date
        if (preg_match("#^from:\d{4}-\d{2}-\d{2}\+to:\d{4}-\d{2}-\d{2}$#i",$keyword)) {
            $results = $this->displayResultsByCustomQuery($keywordArray);
        } else {
           $results = $this->displayResults($keywordArray, $dateCrawl, $keyword);
        }
        //var_dump($results['res'][0]);die;
        $keyword = str_replace("+", " ", $keyword);
		$this->generateGraphJsonFile($results,$keywordArray); //Fichier json pour le graphe
		$this->generatePieChartJsonFile($results); //Fichier json pour le camembert
        return $this->render('SmartSearchSearchBundle:Search:index.html.twig', 
								array(
									  "form" => $form->createView(), "results" => $results,
									  "keywordArray" => $keywordArray, "keyword" => $keyword,
                                      "datesCrawl" => $datesCrawl,
									  "dateCrawl" => $dateCrawl //Date de crawl provenant du paramètre de la route
								));

    }
    // ***********************************
    // Méthode de création de l'index à partir des documents scrappés
    // ***********************************
    public function createIndexAction($dateinput)
    {
        $em = $this->getDoctrine()->getManager();
        $date = new \DateTime($dateinput);

        $reviews = $em->getRepository('SmartSearchSearchBundle:Review')->findBy(array("dateCrawl" => $date), array());
        $collection = array();

        foreach($reviews as $review) {
            $txt = $this->cleanContent($review->getTitle())." ".$this->cleanContent($review->getContent());
            $collection[$review->getId()] = $txt;
        }

        $dictionary = array();
        $docCount = array();

        foreach($collection as $idReview => $review) {

            $terms = explode(' ', $review);
            $docCount[$idReview] = count($terms);

            foreach($terms as $term) {

                if(!isset($dictionary[$term])) {
                    $dictionary[$term] = array('df' => 0, 'postings' => array());
                }

                if(!isset($dictionary[$term]['postings'][$idReview])) {
                    $dictionary[$term]['df']++;
                    $dictionary[$term]['postings'][$idReview] = array('tf' => 0);
                }

                $dictionary[$term]['postings'][$idReview]['tf']++;

            }

        }

        $index = array('docCount' => $docCount, 'dictionary' => $dictionary);

        $indexJson = json_encode($index); // On encode les résultats en JSON
        $fp = fopen(__DIR__.'/../../../../web/docs/index_'.$dateinput, 'w+');
        fwrite($fp, serialize($indexJson)); // On serialize les donnes pour gagner en rapidité et on les écrit dans le fichier index.json
        fclose($fp);

        return $this->redirect($this->generateUrl('smart_search_homepage', array()));

    }
	/**
     * Vérifie si la requête correspond à une entité série spécifique
     * @param string serie : la requête tapée
     * @return boolean
     * */
    public function isSerieEntity($query)
    {
		$series = $this->getDoctrine()->getRepository("SmartSearchSearchBundle:Serie")->findAll();
		foreach($series as $serie) {
			if (strtolower($serie->getName()) == $query) {
				return $serie;
			}
		}
		return false;
	}
    // ***********************************
    // Méthode de récupération de l'index
    // ***********************************
    private function getIndex($dateCrawl)
    {
        $indexJson = file_get_contents(__DIR__."/../../../../web/docs/index_".$dateCrawl);
        $index = json_decode(unserialize($indexJson));
        return $index;
    }


    // ***********************************
    // Méthode de classement des résultats
    // ***********************************
    private function getResults($query, $dateCrawl)
    {
        //var_dump($dateCrawl);
        // $index = $this->createIndex($dateCrawl);
        $index = $this->getIndex($dateCrawl);
        // var_dump($index);
        $matchDocs = array();
        $docCount = count($index->docCount);

        $return = "";
        foreach($query as $term) {

            if(isset($index->dictionary->$term)) {

                $entry = $index->dictionary->$term;

                foreach($entry->postings as $idReview => $posting) {

                    if(!isset($matchDocs[$idReview])) {
                        $matchDocs[$idReview] = $posting->tf * log($docCount + 1 / $entry->df + 1, 2);
                    } else {
                        $matchDocs[$idReview] += $posting->tf * log($docCount + 1 / $entry->df + 1, 2);
                    }
                }
            }
        }

        // Normalisation
        foreach($matchDocs as $idReview => $score) {
                $matchDocs[$idReview] = $score/$index->docCount->$idReview;
        }

        arsort($matchDocs); // On tri par ordre décroissant
        $results = array_slice($matchDocs, 0, 10, true); // On ne garde que le TOP 10.
        return $results;
    }



    // ***********************************
    // Méthode pour récupérer la liste des entités à partir des résultats obtenus par la méthode getResults()
    // ***********************************
    private function displayResults($query, $dateCrawl, $keyword)
    {
        $em = $this->getDoctrine()->getManager();
        $results = $this->getResults($query, $dateCrawl);
        $reviews = array();
		$res = array();
		$sortedDates = array();
		$actors = array();
        foreach($results as $idReview => $score) {
            $review = $em->getRepository('SmartSearchSearchBundle:Review')->find($idReview);
            $serie = $em->getRepository('SmartSearchSearchBundle:Serie')->findOneBy(array('name' => $review->getNameSerie()));
			$sortedDates[] = $review->getDatePublished()->format("Y");
			$actors[] = explode(';',$serie->getActors());
            $res[] = array($review, $serie);
        }
        $reviews['res'] = $res;
        sort($sortedDates);
        $reviews['sortedDates'] = $sortedDates;
        $keyword = str_replace("+", " ", $keyword);
        //$strictSerie = $em->getRepository('SmartSearchSearchBundle:Serie')->findOneBy(array('name' => $query[0]));
        if ($this->isSerieEntity($keyword)!=false) {
			$serie = $this->isSerieEntity($keyword);
			$reviews['serie'] = $serie;
			$reviews['actors'] = explode(';',$serie->getActors());
		} else
			$reviews['serie'] = null;
			
        return $reviews;
    }
    // ***********************************
    // Méthode pour nettoyer du contenu (suppression des virgules, des points et transformation du texte en minuscule)
    // ***********************************
    private function cleanContent($txt)
    {
        $txtCleaned = mb_strtolower($txt, 'UTF-8');
        $txtCleaned = $this->stripAccents($txtCleaned);
        $txtCleaned = str_replace(",", "", $txtCleaned);
        $txtCleaned = str_replace(".", "", $txtCleaned);

        return $txtCleaned;
    }


    // ***********************************
    // Méthode pour remplacer les caractères accentués par leur équivalent sans accent
    // ***********************************
    private function stripAccents($str, $encoding='utf-8'){

        // transformer les caractères accentués en entités HTML
        $str = htmlentities($str, ENT_NOQUOTES, $encoding);

        // remplacer les entités HTML pour avoir juste le premier caractères non accentués
        // Exemple : "&ecute;" => "e", "&Ecute;" => "E", "Ã " => "a" ...
        $str = preg_replace('#&([A-za-z])(?:acute|grave|cedil|circ|orn|ring|slash|th|tilde|uml);#', '\1', $str);

        // Remplacer les ligatures tel que : Œ, Æ ...
        // Exemple "Å“" => "oe"
        $str = preg_replace('#&([A-za-z]{2})(?:lig);#', '\1', $str);
        // Supprimer tout le reste
        $str = preg_replace('#&[^;]+;#', '', $str);

        return $str;
    }
   
    /**
     * Permet d'afficher site des critiques
     * @param int id : l'identifiant de la critique
     * @param dateCrawl : la date du crawl, enfin de pouvoir
     * récupérer le bon fichier html pour chaque critique
     * */
    public function displayReviewAction($id,$dateCrawl)
    {
        $review = $this->getDoctrine()->getRepository("SmartSearchSearchBundle:Review")->findOneBy(array("idReview" =>$id ));
        $file = 'http://'.$_SERVER['SERVER_NAME'].'/'.$review->getFile($dateCrawl);
        return $this->render("SmartSearchSearchBundle:Search:review.html.twig", array("content" => file_get_contents($file)));
    }
    public function graphAction()
    {
        return $this->render("SmartSearchSearchBundle:Search:result-graph.html.twig",array());
    }
    /**
     * Permet de créer le fichier json pour le diagramme camembert
     * @param array $results : le résultat à partir du
     * quel le fichier json sera créé
     * */
    public function generatePieChartJsonFile(array $results)
    {
		$colors = array("#2383c1","#64a61f","#7b6788","#a05c56","#961919",
						   "#d8d239","#e98125","#d0743c","#6ada6a","#0b6197"
						   );
		$genders = array();
		if (sizeof($results['res']) > 0) {
			foreach($results['res'] as $critique) {
				$genders[] = $critique[1]->getGenre();
			}
			$distinctGenders = array_count_values($genders);
			if (sizeof($distinctGenders) > 0) {
				$fileContent = json_decode(file_get_contents(__DIR__.'/../../../../web/tmp/pie_chart_data.json'));
				$data = array();
				$i = 0;
				foreach($distinctGenders as $key => $val) {
					$data[] = (object)array('label' => $key,'value' => $val, 'color' => $colors[$i]);
					$i++;
				}
				$fileContent->data->content = $data;
				file_put_contents(__DIR__.'/../../../../web/tmp/pie_chart_data.json',json_encode($fileContent));
			}
		}
	}
    /**
     * Permet de créer le fichier json pour le graphe
     * @param array $results : le résultat à partir du
     * quel le fichier json sera créé
     * @param string $keywordArray : mot clé déclencheur de la requête
     * */
    public function generateGraphJsonFile(array $results, $keywordArray)
    {
        if (sizeof($results['res']) > 0) {
            $nodes = array();
            $nodes[] = array("name" => implode(" ",$keywordArray));
            $links = array();
            $j = 1;
            //$results = array($results[0],$results[1]);
            for( $i =0; $i < sizeof($results['res']); $i++ ) {
                $nodes[] = array("name" => $results['res'][$i][0]->getTitle(),
								"url" => $results['res'][$i][1]->getWebPath());
                $links[] = array("source" => 0, "target" => $j++ );
            }
            $data = array("nodes" => $nodes, "links"=> $links);
            file_put_contents(__DIR__.'/../../../../web/tmp/search_result.json', json_encode($data));
        }
    }
    /**
     * Renvoie le résultat des requêtes du type from:Startdate to:dateEndDate
     * @param array $query : la requête à analyser
     * */
    public function displayResultsByCustomQuery($query)
    {
        $customQueryFromSide = explode(":",$query[0])[1]; //La partie "from" de la requête
        $customQueryToSide = explode(":",$query[1])[1]; //La partie "to" de la requête
        $reviewRepository = $this->getDoctrine()->getRepository('SmartSearchSearchBundle:Review');
        $reviews = $reviewRepository->findByCustomDate($customQueryFromSide,$customQueryToSide);
        $data = array();
        $res = array();
        if (sizeof($reviews) > 0) {
            foreach ($reviews as $review) {
                $serie = $this->getDoctrine()
                              ->getRepository('SmartSearchSearchBundle:Serie')
                              ->findOneBy(array('name' => $review->getNameSerie()));
                $data[] = array($review, $serie);
                $sortedDates[] = $review->getDatePublished()->format("Y");
            }
        }
        $res['res'] = $data;
        $res['serie'] = null;
        sort($sortedDates);
        $res['sortedDates'] = $sortedDates;
        
        return $res;
    }
}
