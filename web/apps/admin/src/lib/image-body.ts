// SPDX-License-Identifier: Apache-2.0

/** The multipart body of `POST /admin/branding/images/{slot}`: the file under `file`. */
export function imageBody(file: File): FormData {
  const body = new FormData()
  body.append('file', file)
  return body
}
