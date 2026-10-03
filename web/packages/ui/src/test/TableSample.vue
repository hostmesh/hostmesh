<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '../index'
</script>

<template>
  <Table>
    <TableCaption>Websites</TableCaption>
    <TableHeader>
      <TableRow>
        <TableHead scope="col">
          Domain
        </TableHead>
        <TableHead scope="col">
          Status
        </TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow>
        <TableCell>example.com</TableCell>
        <TableCell>Active</TableCell>
      </TableRow>
      <TableRow>
        <TableCell>example.org</TableCell>
        <TableCell>Suspended</TableCell>
      </TableRow>
    </TableBody>
  </Table>
</template>
