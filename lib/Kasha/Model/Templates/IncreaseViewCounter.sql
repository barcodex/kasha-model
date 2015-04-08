UPDATE {{objectType}}
SET
	cnt_viewed = cnt_viewed + 1,
	last_viewed = NOW()
WHERE id = '{{objectId}}'

