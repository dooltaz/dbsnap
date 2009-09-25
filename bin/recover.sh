#!/bin/bash

CURRENT_DIR=`pwd`;
MY_PATH=$(readlink -f $0);
MY_PATH=${MY_PATH%/*};
OUT_PATH=$MY_PATH/../out/;
cd $OUT_PATH;

if tty -s; then
echo  
echo  -- Please choose a file to import 
echo  
for i in `ls | grep -v "^\."`
do
	echo "	$i";
done
read import_file

echo
echo This is the Snap Recovery Script. It will import all
echo of the files in the ./out/ directory to the database
echo that you specify.
echo 
while [ "$server" = "" ]
do
echo Please enter the server to import data to:
read server 
done

while [ "$database" = "" ]
do
echo Please enter the database to import data to:
read database
done

while [ "$username" = "" ]
do
echo Please enter the username:
read username 
done

echo Please enter the password:
read pass 
echo 
echo 

echo "mysql -h $server -u $username -p$pass $database < $OUT_PATH$import_file;";


fi




