<?php

namespace FSphinx\Tests;

use FSphinx\FSphinxClient;
use FSphinx\MultiFieldQuery;
use FSphinx\Facet;
use FSphinx\FacetGroupCache;
use FSphinx\CacheApc;

class IntegrationTest extends \PHPUnit_Framework_TestCase
{
    protected $cl;
    protected $cache;

    protected function setUp()
    {
        $this->cl = new FSphinxClient();
        $this->cl->setServer(SPHINX_HOST, SPHINX_PORT);
        $this->cl->setDefaultIndex('items');
        $this->cl->setMatchMode(FSphinxClient::SPH_MATCH_EXTENDED2);
        $this->cl->setSortMode(FSphinxClient::SPH_SORT_EXPR, '@weight * user_rating_attr * nb_votes_attr * year_attr / 100000');
        $this->cl->setFieldWeights(array('title' => 30));
        $factor = new Facet('actor');
        $factor->attachDataSource($this->cl, array('name' => 'actor_terms'));
        $fdirector = new Facet('director');
        $fdirector->attachDataSource($fdirector, array('name' => 'director_terms_attr'));
        $this->cl->attachFacets(
            new Facet('year'),
            new Facet('genre'),
            new Facet('keyword', array('attr' => 'plot_keyword_attr')),
            $fdirector,
            $factor
        );
        $group_func = 'sum(if (runtime_attr > 45, if (nb_votes_attr > 1000, if (nb_votes_attr < 10000, nb_votes_attr * user_rating_attr, 10000 * user_rating_attr), 1000 * user_rating_attr), 300 * user_rating_attr))';
        foreach ($this->cl->facets as $facet) {
            $facet->setGroupFunc($group_func);
            $facet->setOrderBy('@term', 'asc');
            $facet->setMaxNumValues(5);
        }
        $this->cl->attachQueryParser(new MultiFieldQuery(
            array(
                'genre' => 'genres',
                'keyword' => 'plot_keywords',
                'director' => 'directors',
                'actor' => 'actors'
            ),
            array(
                'keyword' => 'plot_keyword_attr'
            )
        ));
    }

    protected function tearDown()
    {
        if ($this->cache) $this->cache->Clear(true);
    }

    public function testFullQuery()
    {
        $results = $this->cl->query('drama');
        if ($this->cl->isConnectError()) $this->markTestSkipped('Could not connect to Sphinx.');
        if (!$results || !isset($results['matches'])) $this->markTestSkipped('No results returned from Sphinx.');
        $ids = array();
        foreach ($results['matches'] as $id => $result) {
            $ids[] = $id;
        }
        $this->assertEquals(array(
            111161, 468569, 114369, 68646, 137523, 169547, 109830, 108052, 120815, 172495
        ), array_slice($ids, 0, 10));

        $ids = array();
        foreach ($this->cl->facets as $index => $facet) {
            $ids[$index] = array();
            foreach ($facet as $match) {
                $ids[$index][] = $match['@term'];
            }
        }

        $this->assertEquals(array(
            1999, 2003, 2004, 2006, 2008
        ), $ids[0]);
        $this->assertEquals(array(
            'Akira Kurosawa', 'Billy Wilder', 'Clint Eastwood', 'Francis Ford Coppola', 'Stanley Kubrick'
        ), $ids[3]);
        $this->assertEquals(array(
            'Al Pacino', 'John Qualen', 'Morgan Freeman', 'Robert De Niro', 'Robert Duvall'
        ), $ids[4]);
    }

    public function testFullQueryWithCaching()
    {
        if (!extension_loaded('apc') || ini_get('apc.enabled') != '1') {
            $this->markTestSkipped('The APC extension is not loaded.');
        }
        elseif (php_sapi_name() == 'cli' && ini_get('apc.enable_cli') != '1') {
            $this->markTestSkipped('APC is not enabled on command line. Please set apc.enable_cli = 1');
        }

        $this->cache = new FacetGroupCache(new CacheApc());
        $this->cache->clear(true);
        $this->cl->facets->attachCache($this->cache);

        $results = $this->cl->query('drama');
        if ($this->cl->isConnectError()) $this->markTestSkipped('Could not connect to Sphinx.');
        if (!$results || !isset($results['matches'])) $this->markTestSkipped('No results returned from Sphinx.');
        $this->assertGreaterThan(-1, $this->cl->facets->getTime());

        $this->cl->facets->setCaching(true);
        $results = $this->cl->query('drama');
        $this->assertGreaterThan(-1, $this->cl->facets->getTime());
        $results = $this->cl->query('drama');
        $this->assertEquals(-1, $this->cl->facets->getTime());

        $ids = array();
        foreach ($this->cl->facets as $index => $facet) {
            $ids[$index] = array();
            foreach ($facet as $match) {
                $ids[$index][] = $match['@term'];
            }
        }

        $this->assertEquals(array(
            1999, 2003, 2004, 2006, 2008
        ), $ids[0]);
        $this->assertEquals(array(
            'Akira Kurosawa', 'Billy Wilder', 'Clint Eastwood', 'Francis Ford Coppola', 'Stanley Kubrick'
        ), $ids[3]);
        $this->assertEquals(array(
            'Al Pacino', 'John Qualen', 'Morgan Freeman', 'Robert De Niro', 'Robert Duvall'
        ), $ids[4]);
    }

    public function testRefineActor()
    {
        $results = $this->cl->query('drama (@actor "Morgan Freeman")');
        if ($this->cl->isConnectError()) $this->markTestSkipped('Could not connect to Sphinx.');
        if (!$results || !isset($results['matches'])) $this->markTestSkipped('No results returned from Sphinx.');

        $ids = array();
        foreach ($results['matches'] as $id => $result) {
            $ids[] = $id;
        }
        $this->assertEquals(array(
            111161, 468569, 114369, 405159, 105695, 97441
        ), $ids);

        $ids = array();
        foreach ($this->cl->facets as $index => $facet) {
            $ids[$index] = array();
            foreach ($facet as $match) {
                $ids[$index][] = $match['@term'];
            }
        }

        $this->assertEquals(array(
            1992, 1994, 1995, 2004, 2008
        ), $ids[0]);
        $this->assertEquals(array(
            'Christopher Nolan', 'Clint Eastwood', 'David Fincher', 'Edward Zwick', 'Frank Darabont'
        ), $ids[3]);
        $this->assertEquals(array(
            'Bob Gunton', 'Clancy Brown', 'Clint Eastwood', 'Mark Rolston', 'Morgan Freeman', 'Tim Robbins'
        ), $ids[4]);
    }

    public function testRefineDirector()
    {
        $results = $this->cl->query('drama (@actor "Morgan Freeman") (@director "Clint Eastwood")');
        if ($this->cl->isConnectError()) $this->markTestSkipped('Could not connect to Sphinx.');
        if (!$results || !isset($results['matches'])) $this->markTestSkipped('No results returned from Sphinx.');

        $ids = array();
        foreach ($results['matches'] as $id => $result) {
            $ids[] = $id;
        }
        $this->assertEquals(array(
            405159, 105695
        ), $ids);

        $ids = array();
        foreach ($this->cl->facets as $index => $facet) {
            $ids[$index] = array();
            foreach ($facet as $match) {
                $ids[$index][] = $match['@term'];
            }
        }

        $this->assertEquals(array(
            1992, 2004
        ), $ids[0]);
        $this->assertEquals(array(
            'Clint Eastwood'
        ), $ids[3]);
        $this->assertEquals(array(
            'Clint Eastwood', 'David Mucci', 'Jaimz Woolvett', 'Josie Smith', 'Liisa Repo-Martell', 'Morgan Freeman'
        ), $ids[4]);
    }

    public function testProgressiveRefine()
    {
        $results = $this->cl->query('drama');
        $results = $this->cl->query('drama (@actor "Morgan Freeman")');
        $results = $this->cl->query('drama (@actor "Morgan Freeman") (@director "Clint Eastwood")');
        if ($this->cl->isConnectError()) $this->markTestSkipped('Could not connect to Sphinx.');
        if (!$results || !isset($results['matches'])) $this->markTestSkipped('No results returned from Sphinx.');

        $ids = array();
        foreach ($results['matches'] as $id => $result) {
            $ids[] = $id;
        }
        $this->assertEquals(array(
            405159, 105695
        ), $ids);

        $ids = array();
        foreach ($this->cl->facets as $index => $facet) {
            $ids[$index] = array();
            foreach ($facet as $match) {
                $ids[$index][] = $match['@term'];
            }
        }

        $this->assertEquals(array(
            1992, 2004
        ), $ids[0]);
        $this->assertEquals(array(
            'Clint Eastwood'
        ), $ids[3]);
        $this->assertEquals(array(
            'Clint Eastwood', 'David Mucci', 'Jaimz Woolvett', 'Josie Smith', 'Liisa Repo-Martell', 'Morgan Freeman'
        ), $ids[4]);

        // this should return no results
        $results = $this->cl->query('drama (@actor "Morgan Freeman") (@director "Clint Eastwood") (@year 1993)');
        $this->assertEquals(0, $results['total_found']);

        foreach ($this->cl->facets as $index => $facet) {
            $this->assertEquals(0, count($facet));
        }

        // facets should not be computed if there's no results returned for the main query
        $this->assertEquals(0, $this->cl->facets->getTime());
    }

    public function testAttributeFiltering()
    {
        $this->cl->setFiltering(true);
        $results = $this->cl->query('drama (@actor 151) (@director 142)');
        if ($this->cl->isConnectError()) $this->markTestSkipped('Could not connect to Sphinx.');
        if (!$results || !isset($results['matches'])) $this->markTestSkipped('No results returned from Sphinx.');

        $ids = array();

        foreach ($results['matches'] as $id => $result) {
            $ids[] = $id;
        }
        $this->assertEquals(array(
            405159, 105695
        ), $ids);

        $ids = array();
        foreach ($this->cl->facets as $index => $facet) {
            $ids[$index] = array();
            foreach ($facet as $match) {
                $ids[$index][] = $match['@term'];
            }
        }

        $this->assertEquals(array(
            1992, 2004
        ), $ids[0]);
        $this->assertEquals(array(
            'Clint Eastwood'
        ), $ids[3]);
        $this->assertEquals(array(
            'Clint Eastwood', 'David Mucci', 'Jaimz Woolvett', 'Josie Smith', 'Liisa Repo-Martell', 'Morgan Freeman'
        ), $ids[4]);

        // check that the terms have been passed back to the query object
        $query = $this->cl->getQuery();
        $this->assertEquals(
            '(@* drama) (@actor Morgan Freeman) (@director Clint Eastwood)',
            $query->__toString()
        );
    }

    public function testConfigFile()
    {
        $sphinx = FSphinxClient::fromConfig(__DIR__ . '/Fixtures/config.sample.php');
        $this->assertInstanceOf('\FSphinx\FSphinxClient', $sphinx);

        $results1 = $this->cl->query('drama (@actor "Morgan Freeman") (@director "Clint Eastwood")');
        if ($this->cl->isConnectError()) $this->markTestSkipped('Could not connect to Sphinx.');
        if (!$results1 || !isset($results1['matches'])) $this->markTestSkipped('No results returned from Sphinx.');

        $results2 = $sphinx->query('drama (@actor "Morgan Freeman") (@director "Clint Eastwood")');
        $ids1 = $ids2 = array();
        foreach ($this->cl->facets as $index => $facet) {
            $ids1[$index] = array();
            foreach ($facet as $match) {
                $ids1[$index][] = $match['@term'];
            }
        }
        foreach ($sphinx->facets as $index => $facet) {
            $ids2[$index] = array();
            foreach ($facet as $match) {
                $ids2[$index][] = $match['@term'];
            }
        }
        $this->assertEquals($ids1, $ids2);
    }
}
