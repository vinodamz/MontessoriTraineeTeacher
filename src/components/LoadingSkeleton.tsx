export function StudentCardSkeleton() {
  return (
    <div className="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 animate-pulse">
      <div className="flex items-start justify-between mb-3">
        <div className="flex-1">
          <div className="h-4 bg-gray-200 rounded-full w-3/4 mb-2" />
          <div className="h-3 bg-gray-100 rounded-full w-1/2" />
        </div>
        <div className="h-6 w-16 bg-gray-200 rounded-full" />
      </div>
      <div className="h-3 bg-gray-100 rounded-full w-2/3 mb-3" />
      <div className="flex gap-2">
        <div className="h-8 flex-1 bg-gray-100 rounded-xl" />
        <div className="h-8 flex-1 bg-gray-200 rounded-xl" />
      </div>
    </div>
  );
}

export function TableRowSkeleton({ cols }: { cols: number }) {
  return (
    <tr className="animate-pulse">
      {Array.from({ length: cols }).map((_, i) => (
        <td key={i} className="px-4 py-3">
          <div className="h-4 bg-gray-200 rounded-full" />
        </td>
      ))}
    </tr>
  );
}

export function StatCardSkeleton() {
  return (
    <div className="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 animate-pulse">
      <div className="h-4 bg-gray-200 rounded-full w-1/2 mb-3" />
      <div className="h-8 bg-gray-200 rounded-full w-1/3" />
    </div>
  );
}
