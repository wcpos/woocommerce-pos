#!/bin/bash
# Same matrix as bench-2.0.2.txt and bench-fix.txt, run on the query-vars fix
# (post__not_in written again on filtered queries); writes bench-query-vars.txt.
cd "$(dirname "$0")" || exit 1
{
  ./run-matrix.sh qv "off 0:0 5000:5000 20000:20000 50000:50000" "wpq wcgp children visible"
  ./run-matrix.sh qv "off 20000:20000 50000:50000" "both gp"
} > bench-query-vars.txt 2>&1
cat bench-query-vars.txt
