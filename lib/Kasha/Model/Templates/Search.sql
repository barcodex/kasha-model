SELECT basetable.*
FROM {{tableName}} AS basetable
{{joinClause}}
{{whereClause}}
{{orderClause}}
{{limitClause}}
