<?php
/**
 * Base Model with query builder
 * 
 * @package Core
 */

namespace Core;

abstract class Model
{
    /**
     * @var \PDO Database connection
     */
    protected $db;
    
    /**
     * @var string Table name
     */
    protected $table;
    
    /**
     * @var string Primary key
     */
    protected $primaryKey = 'id';
    
    /**
     * @var array Fillable fields
     */
    protected $fillable = [];
    
    /**
     * @var array Hidden fields
     */
    protected $hidden = [];
    
    /**
     * @var array Attributes
     */
    protected $attributes = [];
    
    /**
     * @var array Original attributes
     */
    protected $original = [];
    
    /**
     * @var array Relations
     */
    protected $relations = [];
    
    /**
     * @var \PDOStatement Last query
     */
    protected $lastQuery;
    
    /**
     * @var int Last insert ID
     */
    protected $lastInsertId;
    
    /**
     * @var array Query logs
     */
    protected static $queryLog = [];
    
    /**
     * @var bool Enable query logging
     */
    protected static $enableQueryLog = false;
    
    /**
     * Constructor
     */
    public function __construct(array $attributes = [])
    {
        global $app;
        $this->db = $app->getDb();
        
        $this->fill($attributes);
        $this->syncOriginal();
    }
    
    /**
     * Fill attributes
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if (in_array($key, $this->fillable) || empty($this->fillable)) {
                $this->setAttribute($key, $value);
            }
        }
        
        return $this;
    }
    
    /**
     * Set attribute
     */
    public function setAttribute(string $key, $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }
    
    /**
     * Get attribute
     */
    public function getAttribute(string $key)
    {
        return $this->attributes[$key] ?? null;
    }
    
    /**
     * Sync original attributes
     */
    protected function syncOriginal(): self
    {
        $this->original = $this->attributes;
        return $this;
    }
    
    /**
     * Save model
     */
    public function save(): bool
    {
        if ($this->exists()) {
            return $this->update();
        }
        
        return $this->insert();
    }
    
    /**
     * Check if model exists
     */
    protected function exists(): bool
    {
        return isset($this->attributes[$this->primaryKey]);
    }
    
    /**
     * Insert new record
     */
    protected function insert(): bool
    {
        $fields = array_keys($this->attributes);
        $placeholders = array_fill(0, count($fields), '?');
        
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $fields),
            implode(', ', $placeholders)
        );
        
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute(array_values($this->attributes));
        
        if ($result) {
            $this->setAttribute($this->primaryKey, $this->db->lastInsertId());
            $this->syncOriginal();
            $this->logQuery($sql, $this->attributes);
        }
        
        return $result;
    }
    
    /**
     * Update existing record
     */
    protected function update(): bool
    {
        $fields = [];
        foreach (array_keys($this->getDirty()) as $field) {
            $fields[] = "{$field} = ?";
        }
        
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = ?',
            $this->table,
            implode(', ', $fields),
            $this->primaryKey
        );
        
        $values = array_values($this->getDirty());
        $values[] = $this->getAttribute($this->primaryKey);
        
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute($values);
        
        if ($result) {
            $this->syncOriginal();
            $this->logQuery($sql, $values);
        }
        
        return $result;
    }
    
    /**
     * Delete record
     */
    public function delete(): bool
    {
        if (!$this->exists()) {
            return false;
        }
        
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute([$this->getAttribute($this->primaryKey)]);
    }
    
    /**
     * Find record by primary key
     */
    public static function find($id): ?self
    {
        $instance = new static();
        
        $sql = "SELECT * FROM {$instance->table} WHERE {$instance->primaryKey} = ? LIMIT 1";
        $stmt = $instance->db->prepare($sql);
        $stmt->execute([$id]);
        
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return new static($data);
    }
    
    /**
     * Get all records
     */
    public static function all(): array
    {
        $instance = new static();
        $sql = "SELECT * FROM {$instance->table}";
        
        $stmt = $instance->db->query($sql);
        
        return $stmt->fetchAll(\PDO::FETCH_CLASS, static::class);
    }
    
    /**
     * Where query builder
     */
    public static function where(string $field, string $operator, $value = null): QueryBuilder
    {
        $instance = new static();
        return (new QueryBuilder($instance))->where($field, $operator, $value);
    }
    
    /**
     * Get dirty attributes
     */
    public function getDirty(): array
    {
        $dirty = [];
        
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $value !== $this->original[$key]) {
                $dirty[$key] = $value;
            }
        }
        
        return $dirty;
    }
    
    /**
     * Define hasOne relationship
     */
    protected function hasOne(string $related, string $foreignKey = null, string $localKey = null)
    {
        $localKey = $localKey ?: $this->primaryKey;
        $foreignKey = $foreignKey ?: strtolower(class_basename($this)) . '_id';
        
        return new Relation\HasOne($this, $related, $foreignKey, $localKey);
    }
    
    /**
     * Define hasMany relationship
     */
    protected function hasMany(string $related, string $foreignKey = null, string $localKey = null)
    {
        $localKey = $localKey ?: $this->primaryKey;
        $foreignKey = $foreignKey ?: strtolower(class_basename($this)) . '_id';
        
        return new Relation\HasMany($this, $related, $foreignKey, $localKey);
    }
    
    /**
     * Define belongsTo relationship
     */
    protected function belongsTo(string $related, string $foreignKey = null, string $ownerKey = null)
    {
        $ownerKey = $ownerKey ?: (new $related())->getPrimaryKey();
        $foreignKey = $foreignKey ?: strtolower(class_basename($related)) . '_id';
        
        return new Relation\BelongsTo($this, $related, $foreignKey, $ownerKey);
    }
    
    /**
     * Log query
     */
    protected function logQuery(string $sql, array $params = [])
    {
        if (self::$enableQueryLog) {
            self::$queryLog[] = [
                'sql' => $sql,
                'params' => $params,
                'time' => microtime(true)
            ];
        }
    }
    
    /**
     * Get query log
     */
    public static function getQueryLog(): array
    {
        return self::$queryLog;
    }
    
    /**
     * Enable query logging
     */
    public static function enableQueryLog()
    {
        self::$enableQueryLog = true;
    }
    
    /**
     * Get table name
     */
    public function getTable(): string
    {
        return $this->table;
    }
    
    /**
     * Get primary key
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }
    
    /**
     * Convert to array
     */
    public function toArray(): array
    {
        $attributes = $this->attributes;
        
        foreach ($this->hidden as $hidden) {
            unset($attributes[$hidden]);
        }
        
        return array_merge($attributes, $this->relations);
    }
    
    /**
     * Convert to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
    
    /**
     * Magic getter
     */
    public function __get(string $key)
    {
        return $this->getAttribute($key);
    }
    
    /**
     * Magic setter
     */
    public function __set(string $key, $value)
    {
        $this->setAttribute($key, $value);
    }
}