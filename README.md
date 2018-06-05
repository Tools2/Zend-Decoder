# Zend-Decoder
支持php5.6 zend解密，其他版本未测。

编译xcache

```
git clone https://github.com/lighttpd/xcache
cd xcache
patch -p1 < ../xcache.patch
```
编译参考：https://github.com/lighttpd/xcache/blob/master/INSTALL

安装扩展即可。
使用:

```
php index.php 1.php
```

(https://github.com/lighttpd/xcache/blob/master/lib/Decompiler.class.php)


