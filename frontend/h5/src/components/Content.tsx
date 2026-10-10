import { Button, ImageViewer, Toast } from 'antd-mobile'
import { useState } from 'react'

export function CopyText({ text }: { text: string }) {
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text)
      Toast.show({ content: '文案已复制' })
    } catch {
      Toast.show({ content: '复制失败，请长按文字手动复制' })
    }
  }
  return (
    <div className="h5-card">
      <div style={{ whiteSpace: 'pre-wrap', fontSize: 15, lineHeight: 1.6 }}>{text}</div>
      <Button size="small" color="primary" fill="outline" style={{ marginTop: 10 }} onClick={copy}>
        一键复制文案
      </Button>
    </div>
  )
}

export function MediaBody({ material }: { material: { images?: string[]; video?: string; type?: string } }) {
  const [vis, setVis] = useState(false)
  return (
    <div>
      {material.video ? (
        <video src={material.video} controls style={{ width: '100%', borderRadius: 10, background: '#000' }} />
      ) : null}
      {(material.images ?? []).map((u, i) => (
        <img
          key={i}
          src={u}
          onClick={() => setVis(true)}
          style={{ width: '100%', borderRadius: 10, display: 'block', marginTop: 8 }}
        />
      ))}
      {(material.images ?? []).length > 0 && (
        <ImageViewer.Multi images={material.images} visible={vis} onClose={() => setVis(false)} />
      )}
    </div>
  )
}

export function NoticeBox({ notice }: { notice?: string }) {
  if (!notice?.trim()) return null
  return (
    <div className="h5-card" style={{ background: '#fffbe6' }}>
      <div style={{ fontWeight: 600, marginBottom: 6 }}>发布说明</div>
      <div style={{ whiteSpace: 'pre-wrap', fontSize: 14, color: '#666' }}>{notice.replace(/^#+\s*/gm, '')}</div>
    </div>
  )
}
