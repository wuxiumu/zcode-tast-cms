import { ImageUploader } from 'antd-mobile'
import { upload } from '../api'

/** 截图上传（1~3 张），value 为 /uploads/... url 数组 */
export function ShotsUploader({
  value,
  onChange,
  max = 3,
}: {
  value: string[]
  onChange: (v: string[]) => void
  max?: number
}) {
  return (
    <ImageUploader
      value={value.map((url) => ({ url }))}
      onChange={(items) => onChange(items.map((i) => i.url).filter(Boolean) as string[])}
      upload={async (file: File) => ({ url: await upload(file, 'screenshot') })}
      maxCount={max}
      showUpload={value.length < max}
      accept="image/*"
    />
  )
}
