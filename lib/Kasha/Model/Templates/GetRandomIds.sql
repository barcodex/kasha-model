SELECT id
FROM {{tableName}}
{{whereClause}}
ORDER BY RAND()
LIMIT {{recordCount}}
