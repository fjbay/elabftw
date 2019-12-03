<?php
/**
 * @author Nicolas CARPi <nicolas.carpi@curie.fr>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Elabftw\Db;
use Elabftw\Exceptions\DatabaseErrorException;
use Elabftw\Traits\EntityTrait;
use PDO;

/**
 * All about the team's scheduler
 */
class Scheduler
{
    use EntityTrait;

    /** @var Database $Database instance of Database */
    public $Database;

    /**
     * Constructor
     *
     * @param Database $database
     */
    public function __construct(Database $database)
    {
        $this->Db = Db::getConnection();
        $this->Database = $database;
    }

    /**
     * Add an event for an item in the team
     *
     * @param string $start 2016-07-22T13:37:00
     * @param string $end 2016-07-22T19:42:00
     * @param string $title the comment entered by user
     * @return int the new id
     */
    public function create(string $start, string $end, string $title): int
    {
        $title = filter_var($title, FILTER_SANITIZE_STRING);

        $sql = 'INSERT INTO team_events(team, item, start, end, userid, title)
            VALUES(:team, :item, :start, :end, :userid, :title)';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->Database->Users->userData['team'], PDO::PARAM_INT);
        $req->bindParam(':item', $this->Database->id, PDO::PARAM_INT);
        $req->bindParam(':start', $start);
        $req->bindParam(':end', $end);
        $req->bindParam(':title', $title);
        $req->bindParam(':userid', $this->Database->Users->userData['userid'], PDO::PARAM_INT);

        if ($req->execute() !== true) {
            throw new DatabaseErrorException('Error while executing SQL query.');
        }

        return $this->Db->lastInsertId();
    }

    /**
     * Return an array with events for all items of the team
     *
     * @return array
     */
    public function readAllFromTeam(): array
    {
        // the title of the event is title + Firstname Lastname of the user who booked it
        $sql = "SELECT team_events.title, team_events.id, team_events.start, team_events.end, team_events.userid,
            CONCAT('[', items.title, '] ', team_events.title, ' (', u.firstname, ' ', u.lastname, ')') AS title,
            items.title AS item_title
            FROM team_events
            LEFT JOIN items ON team_events.item = items.id
            LEFT JOIN users AS u ON team_events.userid = u.userid
            WHERE team_events.team = :team";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->Database->Users->userData['team'], PDO::PARAM_INT);
        if ($req->execute() !== true) {
            throw new DatabaseErrorException('Error while executing SQL query.');
        }

        $res = $req->fetchAll();
        if ($res === false) {
            return array();
        }
        return $res;
    }

    /**
     * Return an array with events for this item
     *
     * @return array
     */
    public function read(): array
    {
        // the title of the event is title + Firstname Lastname of the user who booked it
        $sql = "SELECT team_events.*,
            CONCAT(team_events.title, ' (', u.firstname, ' ', u.lastname, ') ', COALESCE(experiments.title, '')) AS title
            FROM team_events
            LEFT JOIN experiments ON (experiments.id = team_events.experiment)
            LEFT JOIN users AS u ON team_events.userid = u.userid
            WHERE team_events.team = :team AND team_events.item = :item";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->Database->Users->userData['team'], PDO::PARAM_INT);
        $req->bindParam(':item', $this->Database->id, PDO::PARAM_INT);
        if ($req->execute() !== true) {
            throw new DatabaseErrorException('Error while executing SQL query.');
        }

        $res = $req->fetchAll();
        if ($res === false) {
            return array();
        }
        return $res;
    }

    /**
     * Read info from an event id
     *
     * @return array
     */
    public function readFromId(): array
    {
        $sql = 'SELECT * from team_events WHERE id = :id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);
        if ($req->execute() !== true) {
            throw new DatabaseErrorException('Error while executing SQL query.');
        }

        $res = $req->fetch();
        if ($res === false) {
            throw new DatabaseErrorException('No data associated with that id');
        }

        return $res;
    }

    /**
     * Update the start (and end) of an event (when you drag and drop it)
     *
     * @param string $start 2016-07-22T13:37:00
     * @param string $end 2016-07-22T13:37:00
     * @return void
     */
    public function updateStart(string $start, string $end): void
    {
        $sql = 'UPDATE team_events SET start = :start, end = :end WHERE team = :team AND id = :id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':start', $start);
        $req->bindParam(':end', $end);
        $req->bindParam(':team', $this->Database->Users->userData['team'], PDO::PARAM_INT);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);

        if ($req->execute() !== true) {
            throw new DatabaseErrorException('Error while executing SQL query.');
        }
    }

    /**
     * Update the end of an event (when you resize it)
     *
     * @param string $end 2016-07-22T13:37:00
     * @return void
     */
    public function updateEnd(string $end): void
    {
        $sql = 'UPDATE team_events SET end = :end WHERE team = :team AND id = :id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':end', $end);
        $req->bindParam(':team', $this->Database->Users->userData['team'], PDO::PARAM_INT);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);

        if ($req->execute() !== true) {
            throw new DatabaseErrorException('Error while executing SQL query.');
        }
    }

    /**
     * Bind an experiment to a calendar event
     *
     * @param int $expid id of the experiment
     * @return void
     */
    public function bind(int $expid): void
    {
        $sql = 'UPDATE team_events SET experiment = :experiment WHERE team = :team AND id = :id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':experiment', $expid, PDO::PARAM_INT);
        $req->bindParam(':team', $this->Database->Users->userData['team'], PDO::PARAM_INT);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);

        if ($req->execute() !== true) {
            throw new DatabaseErrorException('Error while executing SQL query.');
        }
    }

    /**
     * Unbind an experiment from a calendar event
     *
     * @return void
     */
    public function unbind(): void
    {
        $sql = 'UPDATE team_events SET experiment = NULL WHERE team = :team AND id = :id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->Database->Users->userData['team'], PDO::PARAM_INT);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);

        if ($req->execute() !== true) {
            throw new DatabaseErrorException('Error while executing SQL query.');
        }
    }

    /**
     * Remove an event
     *
     * @return void
     */
    public function destroy(): void
    {
        // check permission before deleting
        $event = $this->readFromId();
        // if the user is not the same, check if we are admin
        if ($event['userid'] !== $this->Database->Users->userData['userid']) {
            // admin and sysadmin will have usergroup of 1 or 2
            if ((int) $this->Database->Users->userData['usergroup'] <= 2) {
                // check user is in our team
                $Booker = new Users((int) $event['userid']);
                if ($Booker->userData['team'] !== $this->Database->Users->userData['team']) {
                    throw new ImproperActionException(Tools::error(true));
                }
            }
        }
        $sql = 'DELETE FROM team_events WHERE id = :id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);

        if ($req->execute() !== true) {
            throw new DatabaseErrorException('Error while executing SQL query.');
        }
    }
}
