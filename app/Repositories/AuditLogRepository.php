<?php
// app/Repositories/AuditLogRepository.php
namespace App\Repositories;
use PDO;

class AuditLogRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /**
     * Holt eine paginierte Liste von Logs, optional gefiltert.
     * NEU: Limit kann übergeben werden, Standard 20
     * @param int $page
     * @param int $limit
     * @param array $filters
     * @return array
     */
    public function getLogs(int $page = 1, int $limit = 20, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        // Basis-SQL mit JOIN, um Benutzerinformationen abzurufen
        $sql = "SELECT l.*, u.username, u.first_name, u.last_name
                FROM audit_logs l
                LEFT JOIN users u ON l.user_id = u.user_id";

        list($whereClause, $params) = $this->buildWhereClause($filters);
        $sql .= $whereClause;

        // Verwende den korrekten Spaltennamen 'timestamp'
        $sql .= " ORDER BY l.timestamp DESC";
        $sql .= " LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);

        // Füge Paginierungs-Parameter hinzu
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        // Binde alle Parameter (Filter + Paginierung)
        $this->bindValues($stmt, $params);

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Zählt die Gesamtanzahl der Logs für die Filter.
     *
     * @param array $filters
     * @return int
     */
    public function getLogsCount(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) FROM audit_logs l"; // Alias 'l' ist wichtig
        list($whereClause, $params) = $this->buildWhereClause($filters);
        $sql .= $whereClause;

        $stmt = $this->pdo->prepare($sql);

        // Binde nur die Filter-Parameter
        $this->bindValues($stmt, $params);

        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Baut die WHERE-Klausel und Parameter für die Log-Abfragen.
     *
     * @param array $filters
     * @return array [string $whereClause, array $params]
     */
    private function buildWhereClause(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = "l.user_id = :user_id"; // 'l.' alias ist wichtig
            $params[':user_id'] = $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where[] = "l.action LIKE :action";
            $params[':action'] = '%' . $filters['action'] . '%';
        }
        if (!empty($filters['target_type'])) {
            $where[] = "l.target_type = :target_type";
            $params[':target_type'] = $filters['target_type'];
        }
        if (!empty($filters['start_date'])) {
            // Verwende den korrekten Spaltennamen 'timestamp'
            $where[] = "l.timestamp >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            // Um das gesamte Enddatum einzuschließen (bis 23:59:59)
            // Verwende den korrekten Spaltennamen 'timestamp'
            $where[] = "l.timestamp <= :end_date";
            $params[':end_date'] = $filters['end_date'] . ' 23:59:59';
        }

        $whereClause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

        return [$whereClause, $params];
    }

    /**
     * Hilfsfunktion zum korrekten Binden von Werten an ein PDO-Statement.
     * Behandelt INT- und STR-Typen.
     *
     * @param \PDOStatement $stmt
     * @param array $params
     */
    private function bindValues(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $val) {
            // Bestimme den Typ für bindValue
            if ($key === ':limit' || $key === ':offset' || $key === ':user_id') {
                $stmt->bindValue($key, $val, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $val, PDO::PARAM_STR);
            }
        }
    }

    /**
     * Holt alle eindeutigen Aktions-Typen aus dem Log.
     *
     * @return array
     */
    public function getDistinctActions(): array
    {
        $sql = "SELECT DISTINCT action FROM audit_logs WHERE action IS NOT NULL AND action != '' ORDER BY action ASC";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Holt alle eindeutigen Ziel-Typen aus dem Log.
     *
     * @return array
     */
    public function getDistinctTargetTypes(): array
    {
        $sql = "SELECT DISTINCT target_type FROM audit_logs WHERE target_type IS NOT NULL AND target_type != '' ORDER BY target_type ASC";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}