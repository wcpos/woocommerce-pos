#!/bin/bash
# Same matrix as bench-2.0.2.txt, run on the fixed code; writes bench-fix.txt.
cd "$(dirname "$0")" || exit 1
{
  ./run-matrix.sh fix "off 0:0 5000:5000 20000:20000 50000:50000" "wpq wcgp children visible"
  ./run-matrix.sh fix "off 20000:20000 50000:50000" "both gp"
} > bench-fix.txt 2>&1
cat bench-fix.txt
