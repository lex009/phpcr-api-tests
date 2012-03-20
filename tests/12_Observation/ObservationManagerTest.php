<?php

namespace PHPCR\Tests\Observation;

use \PHPCR\Observation\EventInterface;


require_once(__DIR__ . '/../../inc/BaseCase.php');

/**
 * Tests for the ObservationManager
 *
 * WARNING: With the Jackrabbit backend we noticed that sometimes the journal gets corrupted. If this
 * happens then Jackrabbit will not log anything in the journal anymore. This will make the following
 * tests to fail without a reason why.
 * To correct that problem, please restart Jackrabbit.
 *
 * Covering jcr-2.8.3 spec $12
 */
class ObservationManagerTest extends \PHPCR\Test\BaseCase
{
    // TODO: write some tests for journal filtering with combined filters.
    // All the tests here will only tests filtering on a single criterion.

    public function testGetUnfilteredEventJournal()
    {
        sleep(1); // To avoid having the same date as the journal entries generated by the fixtures loading

        $curTime = strtotime('now');

        $producerSession = self::$loader->getSession();
        $consumerSession = self::$loader->getSession();
        $consumerOm = $consumerSession->getWorkspace()->getObservationManager();

        // Produce some events in the producer session
        $this->produceEvents($producerSession);

        // Read the events in the consumer session
        $this->expectEvents($consumerOm->getEventJournal(), $curTime);
    }

    public function testFilteredEventJournal()
    {
        sleep(1); // To avoid having the same date as the journal entries generated by the fixtures loading or other tests

        $curTime = strtotime('now');

        $session = self::$loader->getSession();
        $om = $session->getWorkspace()->getObservationManager();

        $this->produceEvents($session);

        $this->assertFilterOnEventType($om, $curTime);
        $this->assertFilterOnPathNoMatch($om, $curTime);
        $this->assertFilterOnPathNoDeep($om, $curTime);
        $this->assertFilterOnPathDeep($om, $curTime);
        $this->assertFilterOnUuidNoMatch($om, $curTime);
        $this->assertFilterOnNodeTypeNoMatch($om, $curTime);
        $this->assertFilterOnNodeTypeNoMatch($om, $curTime);
    }

    public function testFilteredEventJournalUuid()
    {
        sleep(1); // To avoid having the same date as the journal entries generated by the fixtures loading or other tests

        $curTime = strtotime('now');
        $session = self::$loader->getSession();
        $om = $session->getWorkspace()->getObservationManager();

        // Make the root node have a UUID
        $root = $session->getRootNode();
        $root->addMixin('mix:referenceable');
        $session->save();

        $root->setProperty('ref', $root, \PHPCR\PropertyType::WEAKREFERENCE);
        $session->save();

        $uuid = $session->getRootNode()->getIdentifier();

        // The journal now contains 2 events a PROP_ADDED (for the prop /ref) and a PERSIST.
        // Filtering on the root node UUID should return only one event in the journal (instead
        // of 2), because the only the PROP_ADDED event was done on a node which parent node
        // has the given UUID.
        $journal = $om->getEventJournal(null, null, null, array($uuid));
        $journal->skipTo($curTime);

        $this->assertTrue($journal->valid());
        $this->assertEquals('/ref', $journal->current()->getPath());
        $this->assertEquals(EventInterface::PROPERTY_ADDED, $journal->current()->getType());

        $journal->next();
        $this->assertFalse($journal->valid());
    }

    public function testFilteredEventJournalNodeType()
    {
        sleep(1); // To avoid having the same date as the journal entries generated by the fixtures loading or other tests

        $curTime = strtotime('now');
        $session = self::$loader->getSession();
        $om = $session->getWorkspace()->getObservationManager();

        // Make the root node have a UUID
        $root = $session->getRootNode();
        $node = $root->addNode('unstructured');
        $session->save();

        // At this point the journal contains 3 events: PROP_ADDED (for setting the node type of the new node)
        // NODE_ADDED and PERSIST. The only of those event whose concerned node is of type nt:unstructured
        // is the NODE_ADDED event.
        $journal = $om->getEventJournal(null, null, null, null, array('nt:unstructured'));
        $journal->skipTo($curTime);

        // At this point the journal
        $this->assertTrue($journal->valid());
        $this->assertEquals('/unstructured', $journal->current()->getPath());
        $this->assertEquals(EventInterface::NODE_ADDED, $journal->current()->getType());

        $journal->next();
        $this->assertFalse($journal->valid());
    }

    protected function assertFilterOnEventType($observationManager, $curTime)
    {
        $journal = $observationManager->getEventJournal(EventInterface::PROPERTY_ADDED);
        $journal->skipTo($curTime);

        $this->assertTrue($journal->valid()); // There must be some events in the journal

        while ($journal->valid()) {
            $event = $journal->current();
            $journal->next();
            $this->assertEquals(EventInterface::PROPERTY_ADDED, $event->getType());
        }
    }

    protected function assertFilterOnPathNoDeep($observationManager, $curTime)
    {
        $journal = $observationManager->getEventJournal(null, '/child');
        $journal->skipTo($curTime);

        $this->assertTrue($journal->valid()); // There must be some events in the journal

        while ($journal->valid()) {
            $event = $journal->current();
            $journal->next();
            $this->assertEquals('/child', $event->getPath());
        }
    }

    protected function assertFilterOnPathDeep($observationManager, $curTime)
    {
        $journal = $observationManager->getEventJournal(null, '/child', true);
        $journal->skipTo($curTime);

        $this->assertTrue($journal->valid()); // There must be some events in the journal

        while ($journal->valid()) {
            $event = $journal->current();
            $journal->next();

            // Notice the assertion is slightly different from the one in testFilterOnPathNoDeep
            $this->assertTrue(substr($event->getPath(), 0, strlen('/child')) === '/child');
        }
    }

    protected function assertFilterOnPathNoMatch($observationManager, $curTime)
    {
        $journal = $observationManager->getEventJournal(null, '/unexisting-path');
        $journal->skipTo($curTime);
        $this->assertFalse($journal->valid()); // No entry match
    }

    protected function assertFilterOnUuidNoMatch($observationManager, $curTime)
    {
        $journal = $observationManager->getEventJournal(null, null, null, array());
        $journal->skipTo($curTime);
        $this->assertFalse($journal->valid());
    }

    protected function assertFilterOnNodeTypeNoMatch($observationManager, $curTime)
    {
        $journal = $observationManager->getEventJournal(null, null, null, null, array('non:existing'));
        $journal->skipTo($curTime);
        $this->assertFalse($journal->valid());
    }

    /**
     * Produce the following entries at the end of the event journal:
     *
     *      PROPERTY_ADDED      /child/jcr:primaryType
     *      NODE_ADDED          /child
     *      PERSIST
     *      PROPERTY_ADDED      /child/prop
     *      PERSIST
     *      PROPERTY_CHANGED    /child/prop
     *      PERSIST
     *      PROPERTY_REMOVED    /child/prop
     *      PERSIST
     *      NODE_REMOVED        /child
     *      PERSIST
     *
     * WARNING:
     * If you change the events (or the order of events) produced here, you
     * will have to adapt self::expectEvents so that it checks for the correct
     * events.
     *
     * @param $session
     * @return void
     */
    protected function produceEvents($session)
    {
        $root = $session->getRootNode();
        $node = $root->addNode('child');             // Will cause a PROPERTY_ADDED + a NODE_ADDED events
        $session->save();                            // Will cause a PERSIST event

        $prop = $node->setProperty('prop', 'value'); // Will case a PROPERTY_ADDED event
        $session->save();                            // Will cause a PERSIST event

        $prop->setValue('something else');           // Will cause a PROPERTY_CHANGED event
        $session->save();                            // Will cause a PERSIST event

        $prop->remove();                             // Will cause a PROPERTY_REMOVED event
        $session->save();                            // Will cause a PERSIST event

        $session->move('/child', '/moved');          // Will cause a NODE_REMOVED + NODE_ADDED + NODE_MOVED events
        $session->save();                            // Will cause a PERSIST event

        $node->remove();                             // Will cause a NODE_REMOVED event
        $session->save();                            // Will cause a PERSIST event
    }

    /**
     * Check if the expected events are in the event journal.
     *
     * WARNING:
     * This function will expect the events produced by self::produceEvents
     * If you add or remove events from self::produceEvents, you will have
     * to adapt this function so that it expects the correct events in the
     * correct order.
     *
     * @param $journal
     * @param int $startDate The timestamp to use with EventJournal::skipTo to reach the wanted events
     * @return void
     */
    protected function expectEvents($journal, $startDate)
    {
        $journal->skipTo($startDate);

        $this->assertTrue($journal->valid());

        // Adding a node will cause a NODE_ADDED + PROPERTY_ADDED (for the primary node type)
        // The order is implementation specific (Jackrabbit will trigger the prop added before the node added event)
        if ($journal->current()->getType() === EventInterface::NODE_ADDED) {
            $this->assertEvent(EventInterface::NODE_ADDED, '/child/', $journal->current());
            $journal->next();
            $this->assertEvent(EventInterface::PROPERTY_ADDED, '/child/jcr%3aprimaryType', $journal->current());
        } else {
            $this->assertEvent(EventInterface::PROPERTY_ADDED, '/child/jcr%3aprimaryType', $journal->current());
            $journal->next();
            $this->assertEvent(EventInterface::NODE_ADDED, '/child', $journal->current());
        }

        $journal->next();
        $this->assertEvent(EventInterface::PERSIST, '', $journal->current());

        $journal->next();
        $this->assertEvent(EventInterface::PROPERTY_ADDED, '/child/prop', $journal->current());

        $journal->next();
        $this->assertEvent(EventInterface::PERSIST, '', $journal->current());

        $journal->next();
        $this->assertEvent(EventInterface::PROPERTY_CHANGED, '/child/prop', $journal->current());

        $journal->next();
        $this->assertEvent(EventInterface::PERSIST, '', $journal->current());

        $journal->next();
        $this->assertEvent(EventInterface::PROPERTY_REMOVED, '/child/prop', $journal->current());

        $journal->next();
        $this->assertEvent(EventInterface::PERSIST, '', $journal->current());

        $journal->next();
        // Same problem as before. Moving a node will cause a NODE_REMOVED + NODE_ADDED + NODE_MOVED
        // The order of the events is implementation specific.
        // TODO: here we expect the NODE_MOVED event to be the last one. This might be wrong on some implementations !
        if ($journal->current()->getType() === EventInterface::NODE_REMOVED) {
            $this->assertEvent(EventInterface::NODE_REMOVED, '/child', $journal->current());
            $journal->next();
            $this->assertEvent(EventInterface::NODE_ADDED, '/moved', $journal->current());
            $journal->next();
            $this->assertEvent(EventInterface::NODE_MOVED, '/moved', $journal->current());
        } else {
            $this->assertEvent(EventInterface::NODE_ADDED, '/moved', $journal->current());
            $journal->next();
            $this->assertEvent(EventInterface::NODE_REMOVED, '/child', $journal->current());
            $journal->next();
            $this->assertEvent(EventInterface::NODE_MOVED, '/moved', $journal->current());
        }

        $journal->next();
        $this->assertEvent(EventInterface::PERSIST, '', $journal->current());

        $journal->next();
        $this->assertEvent(EventInterface::NODE_REMOVED, '/moved', $journal->current());

        $journal->next();
        $this->assertEvent(EventInterface::PERSIST, '', $journal->current());

        $journal->next();
        $this->assertFalse($journal->valid());
    }

    /**
     * Assert an event of the event journal has the expected type and path.
     * @param int $expectedType
     * @param string $expectedPath
     * @param \PHPCR\Observation\EventInterface $event
     * @return void
     */
    protected function assertEvent($expectedType, $expectedPath, EventInterface $event)
    {
        $this->assertInstanceOf('\PHPCR\Observation\EventInterface', $event);
        $this->assertEquals($expectedType, $event->getType());
        $this->assertEquals($expectedPath, $event->getPath());
    }

    /**
     * Internal function used to dump the events in the journal for debugging
     * @param $journal
     * @return void
     */
    protected function varDumpJournal($journal)
    {
        echo "JOURNAL DUMP:\n";
        while ($journal->valid()) {
            $event = $journal->current();
            echo sprintf("%s - %s - %s\n", $event->getDate(), $event->getType(), $event->getPath())   ;
            $journal->next();
        }
    }
}
