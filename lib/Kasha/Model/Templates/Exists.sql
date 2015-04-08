SELECT COUNT(basetable.id) AS cnt
FROM {{tableName}} AS basetable
{{joinClause}}
{{whereClause}}
